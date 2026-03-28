<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MikrotikRouter;
use App\Models\Plan;
use App\Services\MikrotikRawClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ISP-level MikroTik operations (multi-router).
 *
 * Unlike MikrotikService (single router via .env), this service
 * executes operations against any registered router from the DB.
 */
class MikrotikIspService
{
    private const CACHE_TTL = 180; // 3 minutes

    /* ────────────────────────────────────────────────────────
     |  Connection helper — connects, runs callback, disconnects
     ──────────────────────────────────────────────────────── */

    private const MAX_RETRIES   = 2;
    private const RETRY_DELAY_S  = 2;

    private function withRouter(int $routerId, string $operation, callable $callback): mixed
    {
        $router = MikrotikRouter::find($routerId);

        if (! $router) {
            throw new RuntimeException("Router ID {$routerId} no encontrado.");
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $client = null;
            try {
                $client = new MikrotikRawClient(
                    host: $router->host,
                    user: $router->username,
                    pass: $router->decrypted_password,
                    port: (int) $router->port,
                    ssl:  (bool) $router->use_tls,
                );

                $result = $callback($client);
                Log::debug("[MikrotikISP:{$operation}] Success on router \"{$router->name}\" (attempt " . ($attempt + 1) . ')');
                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("[MikrotikISP:{$operation}] Attempt " . ($attempt + 1) . " failed on \"{$router->name}\": {$e->getMessage()}");
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY_S);
                }
            } finally {
                if ($client) {
                    $client->close();
                }
            }
        }

        Log::error("[MikrotikISP:{$operation}] All retries exhausted on router \"{$router->name}\"");
        throw new RuntimeException("Error en router \"{$router->name}\": {$lastException->getMessage()}");
    }

    private function sendCommand(MikrotikRawClient $client, string $command, array $params = []): array
    {
        return $client->command($command, $params);
    }

    /* ════════════════════════════════════════════════════════
     |  1. ONLINE USERS — PPPoE active sessions
     ════════════════════════════════════════════════════════ */

    public function getOnlineUsers(int $routerId): array
    {
        return $this->withRouter($routerId, 'getOnlineUsers', function (MikrotikRawClient $client) {
            $sessions = $this->sendCommand($client, '/ppp/active/print');

            return array_map(fn ($s) => [
                'name'     => $s['name'] ?? '',
                'address'  => $s['address'] ?? '',
                'uptime'   => $s['uptime'] ?? '',
                'service'  => $s['service'] ?? 'pppoe',
                'callerId' => $s['caller-id'] ?? '',
            ], $sessions);
        });
    }

    /* ════════════════════════════════════════════════════════
     |  2. SYNC — Compare DB ↔ MikroTik
     ════════════════════════════════════════════════════════ */

    public function syncRouterWithDb(int $routerId): array
    {
        $routerUsers = $this->withRouter($routerId, 'sync', function (MikrotikRawClient $client) {
            $secrets = $this->sendCommand($client, '/ppp/secret/print');
            return array_map(fn ($s) => $s['name'] ?? '', $secrets);
        });

        $dbUsers = Client::where('mikrotik_router_id', $routerId)
            ->whereNotNull('mikrotik_user')
            ->pluck('mikrotik_user')
            ->toArray();

        $routerSet = array_flip($routerUsers);
        $dbSet     = array_flip($dbUsers);

        $soloEnRouter  = array_values(array_filter($routerUsers, fn ($u) => ! isset($dbSet[$u])));
        $soloEnBD      = array_values(array_filter($dbUsers, fn ($u) => ! isset($routerSet[$u])));
        $sincronizados = count(array_filter($dbUsers, fn ($u) => isset($routerSet[$u])));

        return [
            'totalEnRouter'  => count($routerUsers),
            'totalEnBD'      => count($dbUsers),
            'soloEnRouter'   => $soloEnRouter,
            'soloEnBD'       => $soloEnBD,
            'sincronizados'  => $sincronizados,
        ];
    }

    /* ════════════════════════════════════════════════════════
     |  3. ENSURE PROFILES — Auto-create PPP profiles from plans
     ════════════════════════════════════════════════════════ */

    public function ensureAllProfiles(int $routerId): array
    {
        $plans = Plan::where('activo', true)->get();

        $result = [
            'created'       => [],
            'alreadyExists' => [],
            'errors'        => [],
        ];

        foreach ($plans as $plan) {
            try {
                $profileName = $plan->nombre;
                $bajada = $plan->velocidad_bajada;
                $subida = $plan->velocidad_subida ?: (int) ceil($bajada / 4);
                $rateLimit = "{$subida}M/{$bajada}M";

                $created = $this->withRouter($routerId, 'ensureProfile', function (MikrotikRawClient $client) use ($profileName, $rateLimit, $plan) {
                    $existing = $this->sendCommand($client, '/ppp/profile/print', ['?name' => $profileName]);

                    if (! empty($existing)) {
                        return false;
                    }

                    $this->sendCommand($client, '/ppp/profile/add', [
                        'name'       => $profileName,
                        'rate-limit' => $rateLimit,
                        'comment'    => "GO Auto: {$plan->nombre} ({$rateLimit})",
                    ]);

                    return true;
                });

                if ($created) {
                    $result['created'][] = $profileName;
                } else {
                    $result['alreadyExists'][] = $profileName;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "{$plan->nombre}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /* ════════════════════════════════════════════════════════
     |  4. CORTE MOROSO — Address-list read-only
     ════════════════════════════════════════════════════════ */

    public function getMoresoFromRouter(int $routerId): array
    {
        $cacheKey = "mikrotik:morosos:{$routerId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $entries = $this->withRouter($routerId, 'getMoreso', function (MikrotikRawClient $client) {
            return $this->sendCommand($client, '/ip/firewall/address-list/print', [
                '?list' => 'CORTE MOROSO',
            ]);
        });

        // Load ALL clients for name matching and IP disambiguation
        $allClients = Client::select('id', 'nombres', 'apellidos', 'ip_address')->get();

        $morosos = array_map(function ($entry) use ($allClients) {
            $item = [
                'id'           => $entry['.id'] ?? '',
                'list'         => $entry['list'] ?? 'CORTE MOROSO',
                'address'      => $entry['address'] ?? '',
                'comment'      => $entry['comment'] ?? null,
                'creationTime' => $entry['creation-time'] ?? null,
                'disabled'     => ($entry['disabled'] ?? 'false') === 'true',
                'clientId'     => null,
                'clientNombre' => null,
            ];

            $comment = trim($item['comment'] ?? '');
            if (empty($comment)) {
                return $item;
            }

            // Try ID-based match first
            if (preg_match('/\bID\s*[>:=]?\s*(\d+)/i', $comment, $m) ||
                preg_match('/\((\d+)\)/', $comment, $m)) {
                $candidate = $allClients->firstWhere('id', (int) $m[1]);
                if ($candidate) {
                    $item['clientId']     = $candidate->id;
                    $item['clientNombre'] = $candidate->nombre_completo;
                    return $item;
                }
            }

            // Name matching with IP disambiguation
            $candidates = $this->findCandidateClientsByName($comment, $allClients);
            $match = $this->pickClientFromCandidates($candidates, $item['address'] ?? null);
            if ($match) {
                $item['clientId']     = $match->id;
                $item['clientNombre'] = $match->nombre_completo;
            }

            return $item;
        }, $entries);

        Cache::put($cacheKey, $morosos, self::CACHE_TTL);

        return $morosos;
    }

    /**
     * Sincroniza morosos + IPs de secrets del router con la BD.
     *
     * 1. PPP secrets → match por nombre → guarda ip_address de TODOS
     * 2. CORTE MOROSO → match por nombre → marca service_status='suspendido'
     * 3. Clientes que ya no están en CORTE MOROSO → service_status='activo'
     *
     * Nota: NO modifica el campo comercial `estado` para respetar cambios
     * manuales hechos por el administrador en revisión de cliente.
     */
    public function syncMorososToDb(int $routerId): array
    {
        Cache::forget("mikrotik:morosos:{$routerId}");
        Cache::forget("mikrotik:secrets:{$routerId}");

        $allClients = Client::select('id', 'nombres', 'apellidos', 'mikrotik_user', 'ip_address')->get();
        $matchedClientIds = []; // Track all clients matched to this router

        // ── 1. IPs from PPP Secrets ──────────────────────────
        $secrets = $this->getSecrets($routerId);
        $ipsUpdated = 0;

        foreach ($secrets as $secret) {
            $ip = $secret['remoteAddress'] ?? null;
            $comment = trim($secret['comment'] ?? '');
            $secretName = $secret['name'] ?? '';

            if (empty($ip)) {
                continue;
            }

            $matchedClient = null;

            // Match by mikrotik_user (exact)
            if (! empty($secretName)) {
                $matchedClient = $allClients->first(fn ($c) => $c->mikrotik_user === $secretName);
            }

            // Match by comment → name
            if (! $matchedClient && ! empty($comment)) {
                $candidates = $this->findCandidateClientsByName($comment, $allClients);
                $matchedClient = $this->pickClientFromCandidates($candidates, $ip);
            }

            if ($matchedClient) {
                $matchedClientIds[] = $matchedClient->id;
                $dbClient = Client::find($matchedClient->id);
                if ($dbClient) {
                    $changes = [];
                    if ($dbClient->ip_address !== $ip) {
                        $changes['ip_address'] = $ip;
                    }
                    if ($dbClient->mikrotik_router_id !== $routerId) {
                        $changes['mikrotik_router_id'] = $routerId;
                    }
                    if (! empty($changes)) {
                        $dbClient->updateQuietly($changes);
                        if (isset($changes['ip_address'])) $ipsUpdated++;
                    }
                }
            }
        }

        // ── 2. CORTE MOROSO → mark as suspended in service_status ─────────
        $morosos = $this->getMoresoFromRouter($routerId);
        $morosoClientIds = [];
        $morosUpdated = 0;

        foreach ($morosos as $entry) {
            if (! $entry['clientId']) {
                continue;
            }

            $morosoClientIds[] = $entry['clientId'];
            $matchedClientIds[] = $entry['clientId'];

            $client = Client::find($entry['clientId']);
            if (! $client) {
                continue;
            }

            $changes = [];
            if ($client->service_status !== 'suspendido') {
                $changes['service_status'] = 'suspendido';
            }
            if (! empty($entry['address']) && $client->ip_address !== $entry['address']) {
                $changes['ip_address'] = $entry['address'];
            }
            if ($client->mikrotik_router_id !== $routerId) {
                $changes['mikrotik_router_id'] = $routerId;
            }

            if (! empty($changes)) {
                $client->updateQuietly($changes);
                $morosUpdated++;
            }
        }

        // ── 3. Restore non-morosos → service_status 'activo' ──────────────
        // Clients linked to this router (by router_id or matched in this sync)
        $allLinkedIds = array_unique(array_merge(
            $matchedClientIds,
            Client::where('mikrotik_router_id', $routerId)->pluck('id')->toArray()
        ));

        $restorableIds = array_diff($allLinkedIds, $morosoClientIds);

        $restored = 0;
        if (! empty($restorableIds)) {
            $restored = Client::whereIn('id', $restorableIds)
                ->where('service_status', 'suspendido')
                ->update(['service_status' => 'activo']);
        }

        return [
            'success'      => true,
            'ipsUpdated'   => $ipsUpdated,
            'morosos'      => count($morosoClientIds),
            'morosUpdated' => $morosUpdated,
            'restored'     => $restored,
            'message'      => "Sync: {$ipsUpdated} IPs actualizadas, {$morosUpdated} suspendidos en servicio, {$restored} restaurados.",
        ];
    }

    /**
     * Match a client by comparing a comment string against client names.
     * Uses token-based matching: splits name into tokens ≥2 chars,
     * ALL tokens must exist in the source text for a match.
     */
    private function matchClientByName(string $comment, $clients): ?Client
    {
        $candidates = $this->findCandidateClientsByName($comment, $clients);
        return $this->pickClientFromCandidates($candidates, null);
    }

    /**
     * @return array<int, array{client: Client, score: float}>
     */
    private function findCandidateClientsByName(string $comment, $clients): array
    {
        $commentNorm = $this->extractComparableNameFromComment($comment);
        $matches = [];

        foreach ($clients as $c) {
            $score = $this->nameMatchScore($commentNorm, $c->nombres, $c->apellidos);
            if ($score >= 0.6) {
                $matches[] = ['client' => $c, 'score' => $score];
            }
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $matches;
    }

    private function pickClientFromCandidates(array $candidates, ?string $ip): ?Client
    {
        if (empty($candidates)) {
            return null;
        }

        if (count($candidates) === 1) {
            return $candidates[0]['client'];
        }

        $ip = trim((string) $ip);
        if ($ip !== '') {
            $ipMatches = array_values(array_filter($candidates, function ($c) use ($ip) {
                return trim((string) ($c['client']->ip_address ?? '')) === $ip;
            }));

            if (count($ipMatches) === 1) {
                return $ipMatches[0]['client'];
            }
        }

        // Fall back to the strongest name match when score is clearly better.
        if (count($candidates) >= 2) {
            $best = $candidates[0];
            $second = $candidates[1];
            if (($best['score'] - $second['score']) >= 0.15) {
                return $best['client'];
            }
        }

        return $candidates[0]['client'] ?? null;
    }

    /**
     * Normalize text: lowercase + strip accents via NFD decomposition.
     */
    private function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Decompose accented chars and strip combining marks
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\pM/u', '', $s);
        } else {
            $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        }
        // Strip common punctuation and collapse whitespace
        $s = str_replace([',', ';', '"', "'", '(', ')', '[', ']'], ' ', $s);
        return preg_replace('/\s+/', ' ', trim($s));
    }

    /**
     * Extracts comparable client-name text from noisy MikroTik comments.
     * Supports patterns seen in production, such as:
     * - "CORTE MOROSO - Cliente: NOMBRE (ID 123) - Motivo: ..."
     * - "CLI APELLIDOS, NOMBRES SUS 1234"
     */
    private function extractComparableNameFromComment(string $comment): string
    {
        $s = $this->normalizeText($comment);

        // Remove known prefixes.
        $s = preg_replace('/^corte\s+moroso\b\s*[-:]?\s*/u', '', $s);
        $s = preg_replace('/^cliente\s*:\s*/u', '', $s);
        $s = preg_replace('/^cli\b\s*/u', '', $s);

        // Remove known suffix blocks.
        $s = preg_replace('/\bid\s*\d+\b/u', ' ', $s);
        $s = preg_replace('/\bsus\s*\d+\b/u', ' ', $s);
        $s = preg_replace('/\bmotivo\s*:\s*.*/u', ' ', $s);
        $s = preg_replace('/\bsuspension\s+por\s+morosidad\b/u', ' ', $s);

        // Remove residual separators.
        $s = preg_replace('/\s*-\s*/u', ' ', $s);

        return preg_replace('/\s+/', ' ', trim($s));
    }

    /**
     * Build identity tokens from a name (≥2 chars each).
     * Example: "JOSUE ANDRES" → ["josue", "andres"]
     */
    private function buildIdentityTokens(string $name): array
    {
        $normalized = $this->normalizeText($name);
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map(function ($t) {
            // Remove trailing digits and non-letter chars (e.g. CARPIO1 -> carpio)
            $t = preg_replace('/\d+$/u', '', $t);
            $t = preg_replace('/[^\pL]/u', '', $t);
            return $t;
        }, $tokens);

        return array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));
    }

    /**
     * Token-based name matching: ALL tokens from nombres + apellidos
     * must exist in the source text.
     */
    private function matchesFullName(string $source, string $nombres, string $apellidos): bool
    {
        return $this->nameMatchScore($source, $nombres, $apellidos) >= 0.6;
    }

    private function nameMatchScore(string $source, string $nombres, string $apellidos): float
    {
        $nameTokens = $this->buildIdentityTokens($nombres);
        $lastNameTokens = $this->buildIdentityTokens($apellidos);
        $allTokens = array_values(array_unique(array_merge($nameTokens, $lastNameTokens)));

        if (empty($allTokens)) {
            return false;
        }

        $matched = 0;
        foreach ($allTokens as $token) {
            if (str_contains($source, $token)) {
                $matched++;
            }
        }

        $ratio = $matched / max(count($allTokens), 1);

        // Require at least one surname token match when available.
        $lastNameMatched = 0;
        foreach ($lastNameTokens as $token) {
            if (str_contains($source, $token)) {
                $lastNameMatched++;
            }
        }

        if (! empty($lastNameTokens) && $lastNameMatched === 0) {
            return 0.0;
        }

        // Small boost when surname is strongly present.
        if (! empty($lastNameTokens)) {
            $ratio += 0.1 * ($lastNameMatched / count($lastNameTokens));
        }

        return min($ratio, 1.0);
    }

    private function hasIpInEntries(array $entries, string $ip, string $ipKey): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        foreach ($entries as $entry) {
            if (trim((string) ($entry[$ipKey] ?? '')) === $ip) {
                return true;
            }
        }

        return false;
    }

    // Keep backward compat alias
    private function normalizeName(string $s): string
    {
        return $this->normalizeText($s);
    }

    /**
     * Build per-client MikroTik snapshot for list views.
     *
     * Rules:
     * - MOROSO: found in CORTE MOROSO by normalized full-name match.
     * - NO MOROSO: not in CORTE MOROSO but found in PPP secrets by normalized full-name match.
     * - SIN DATOS: no reliable match in either source.
     *
     * IP priority:
     * 1) CORTE MOROSO address (if MOROSO)
     * 2) PPP secret remoteAddress (if NO MOROSO)
     * 3) null
     *
     * @param  iterable<Client>  $clients
     * @return array<int, array{status:string, ip:?string}>
     */
    public function buildClientMikrotikSnapshot(int $routerId, iterable $clients): array
    {
        $clientList = [];
        foreach ($clients as $c) {
            if ($c instanceof Client) {
                $clientList[] = $c;
            }
        }

        if (empty($clientList)) {
            return [];
        }

        $morosos = $this->getMoresoFromRouter($routerId);
        $secrets = $this->getSecrets($routerId);

        $morosoByIp = [];
        foreach ($morosos as $entry) {
            $ip = trim((string) ($entry['address'] ?? ''));
            if ($ip !== '') {
                $morosoByIp[$ip] = $entry;
            }
        }

        $secretByIp = [];
        $secretByName = [];
        foreach ($secrets as $entry) {
            $ip = trim((string) ($entry['remoteAddress'] ?? ''));
            if ($ip !== '') {
                $secretByIp[$ip] = $entry;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $secretByName[$name] = $entry;
            }
        }

        $result = [];

        foreach ($clientList as $client) {
            $result[$client->id] = [
                'status' => 'sin_datos',
                'ip' => null,
            ];

            $clientUser = trim((string) ($client->mikrotik_user ?? ''));
            if ($clientUser !== '' && isset($secretByName[$clientUser])) {
                $secret = $secretByName[$clientUser];
                $secretIp = trim((string) ($secret['remoteAddress'] ?? ''));

                if ($secretIp !== '' && isset($morosoByIp[$secretIp])) {
                    $result[$client->id] = [
                        'status' => 'moroso',
                        'ip' => $secretIp,
                    ];
                    continue;
                }

                $result[$client->id] = [
                    'status' => 'no_moroso',
                    'ip' => $secretIp !== '' ? $secretIp : null,
                ];
                continue;
            }

            // If client has a known IP, this is strong evidence for MOROSO in CORTE MOROSO.
            $clientIp = trim((string) ($client->ip_address ?? ''));
            if ($clientIp !== '') {
                if (isset($morosoByIp[$clientIp])) {
                    $result[$client->id] = [
                        'status' => 'moroso',
                        'ip' => $clientIp,
                    ];
                    continue;
                }

                // NO MOROSO only when there is supporting evidence in active secrets.
                if (isset($secretByIp[$clientIp])) {
                    $result[$client->id] = [
                        'status' => 'no_moroso',
                        'ip' => $clientIp,
                    ];
                    continue;
                }
            }

            $morosoPick = $this->pickBestEntryForClient(
                client: $client,
                entries: $morosos,
                commentKey: 'comment',
                ipKey: 'address',
            );

            if ($morosoPick['entry']) {
                $morosoIp = trim((string) ($morosoPick['entry']['address'] ?? ''));
                $result[$client->id] = [
                    'status' => 'moroso',
                    'ip' => $morosoIp !== '' ? $morosoIp : null,
                ];
                continue;
            }

            if ($morosoPick['ambiguous']) {
                // If IP explicitly not in CORTE, don't report MOROSO because of ambiguous names.
                if ($clientIp !== '' && ! $this->hasIpInEntries($morosos, $clientIp, 'address')) {
                    $result[$client->id] = [
                        'status' => 'sin_datos',
                        'ip' => null,
                    ];
                    continue;
                }

                $result[$client->id] = [
                    'status' => 'sin_datos',
                    'ip' => null,
                ];
                continue;
            }

            $secretPick = $this->pickBestEntryForClient(
                client: $client,
                entries: $secrets,
                commentKey: 'comment',
                ipKey: 'remoteAddress',
            );

            if ($secretPick['entry']) {
                $result[$client->id] = [
                    'status' => 'no_moroso',
                    'ip' => $secretPick['entry']['remoteAddress'] ?? null,
                ];
                continue;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{entry:?array, ambiguous:bool}
     */
    private function pickBestEntryForClient(Client $client, array $entries, string $commentKey, string $ipKey): array
    {
        $matches = [];

        foreach ($entries as $entry) {
            $comment = trim((string) ($entry[$commentKey] ?? ''));
            if ($comment === '') {
                continue;
            }

            $candidateName = $this->extractComparableNameFromComment($comment);
            if ($candidateName === '') {
                continue;
            }

            if ($this->matchesFullName($candidateName, $client->nombres, $client->apellidos)) {
                $matches[] = $entry;
            }
        }

        if (count($matches) === 0) {
            return ['entry' => null, 'ambiguous' => false];
        }

        if (count($matches) === 1) {
            return ['entry' => $matches[0], 'ambiguous' => false];
        }

        $clientIp = trim((string) ($client->ip_address ?? ''));
        if ($clientIp !== '') {
            foreach ($matches as $m) {
                if (trim((string) ($m[$ipKey] ?? '')) === $clientIp) {
                    return ['entry' => $m, 'ambiguous' => false];
                }
            }
        }

        return ['entry' => null, 'ambiguous' => true];
    }

    /* ════════════════════════════════════════════════════════
     |  4b. SYNC SINGLE CLIENT — Auto-sync one client
     ════════════════════════════════════════════════════════ */

    /**
     * Sync a single client against all active routers.
     * Finds their IP from PPP secrets and moroso status from CORTE MOROSO.
     */
    public function syncSingleClient(Client $client): array
    {
        $routers = MikrotikRouter::where('is_active', true)->get();

        if ($routers->isEmpty()) {
            return ['matched' => false, 'reason' => 'No hay routers activos'];
        }

        $tokens = $this->buildIdentityTokens("{$client->nombres} {$client->apellidos}");

        foreach ($routers as $router) {
            try {
                $result = $this->trySyncClientOnRouter($client, $router, $tokens);
                if ($result['matched']) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning("[MikrotikISP:syncSingleClient] Error on router \"{$router->name}\": {$e->getMessage()}");
            }
        }

        return ['matched' => false, 'reason' => 'Cliente no encontrado en ningún router'];
    }

    private function trySyncClientOnRouter(Client $client, MikrotikRouter $router, array $tokens): array
    {
        $rawClient = new MikrotikRawClient(
            host: $router->host,
            user: $router->username,
            pass: $router->decrypted_password,
            port: (int) $router->port,
            ssl:  (bool) $router->use_tls,
        );

        $changes = [];
        $matched = false;

        try {
            // ── Search PPP Secrets for IP ──
            $secrets = $rawClient->command('/ppp/secret/print');

            foreach ($secrets as $s) {
                $comment = $s['comment'] ?? '';
                $secretName = $s['name'] ?? '';
                $ip = $s['remote-address'] ?? null;

                if (empty($ip)) continue;

                $matchedByUser = !empty($client->mikrotik_user) && $client->mikrotik_user === $secretName;

                $matchedByName = false;
                if (!empty($comment) && !empty($tokens)) {
                    $commentNorm = $this->extractComparableNameFromComment($comment);
                    $matchedByName = true;
                    foreach ($tokens as $token) {
                        if (! str_contains($commentNorm, $token)) {
                            $matchedByName = false;
                            break;
                        }
                    }
                }

                if ($matchedByUser || $matchedByName) {
                    $matched = true;
                    if ($client->ip_address !== $ip) {
                        $changes['ip_address'] = $ip;
                    }
                    if ($client->mikrotik_router_id !== $router->id) {
                        $changes['mikrotik_router_id'] = $router->id;
                    }
                    break;
                }
            }

            if (!$matched) {
                return ['matched' => false];
            }

            // ── Check CORTE MOROSO status ──
            $morosos = $rawClient->command('/ip/firewall/address-list/print', ['?list' => 'CORTE MOROSO']);
            $isMoroso = false;

            foreach ($morosos as $m) {
                $comment = $m['comment'] ?? '';
                if (empty($comment) || empty($tokens)) continue;

                $commentNorm = $this->extractComparableNameFromComment($comment);
                $allMatch = true;
                foreach ($tokens as $token) {
                    if (! str_contains($commentNorm, $token)) {
                        $allMatch = false;
                        break;
                    }
                }

                if ($allMatch) {
                    $isMoroso = true;
                    if (empty($changes['ip_address']) && !empty($m['address']) && empty($client->ip_address)) {
                        $changes['ip_address'] = $m['address'];
                    }
                    break;
                }
            }

            $newServiceStatus = $isMoroso ? 'suspendido' : 'activo';
            if ($client->service_status !== $newServiceStatus) {
                $changes['service_status'] = $newServiceStatus;
            }

            if (!empty($changes)) {
                $client->updateQuietly($changes);
            }

            Log::info("[MikrotikISP:syncSingleClient] {$client->nombre_completo} → IP=" . ($changes['ip_address'] ?? $client->ip_address ?? 'N/A') . " service_status={$newServiceStatus}");

            return [
                'matched'  => true,
                'router'   => $router->name,
                'ip'       => $changes['ip_address'] ?? $client->ip_address,
                'service_status' => $newServiceStatus,
                'changes'  => $changes,
            ];
        } finally {
            $rawClient->close();
        }
    }

    /* ════════════════════════════════════════════════════════
     |  5. PPP PROFILES — CRUD
     ════════════════════════════════════════════════════════ */

    public function getProfiles(int $routerId): array
    {
        return $this->withRouter($routerId, 'getProfiles', function (MikrotikRawClient $client) {
            $rows = $this->sendCommand($client, '/ppp/profile/print');

            $profiles = array_map(fn ($r) => $this->mapProfile($r), $rows);

            usort($profiles, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $profiles;
        });
    }

    public function createProfile(int $routerId, array $data): array
    {
        return $this->withRouter($routerId, 'createProfile', function (MikrotikRawClient $client) use ($data) {
            $name = trim($data['name'] ?? '');
            if (empty($name)) {
                throw new RuntimeException('El nombre del perfil es obligatorio.');
            }

            $existing = $this->sendCommand($client, '/ppp/profile/print', ['?name' => $name]);
            if (! empty($existing)) {
                throw new RuntimeException("Ya existe un perfil con nombre \"{$name}\" en el router.");
            }

            $payload = $this->buildProfilePayload($data);
            $this->sendCommand($client, '/ppp/profile/add', $payload);

            // Re-read created profile
            $created = $this->sendCommand($client, '/ppp/profile/print', ['?name' => $name]);

            return ! empty($created) ? $this->mapProfile($created[0]) : ['name' => $name];
        });
    }

    public function updateProfile(int $routerId, string $profileId, array $data): array
    {
        return $this->withRouter($routerId, 'updateProfile', function (MikrotikRawClient $client) use ($profileId, $data) {
            $current = $this->findProfile($client, $profileId);
            if (! $current) {
                throw new RuntimeException('Perfil no encontrado en el router.');
            }

            if (isset($data['name'])) {
                $nextName = trim($data['name']);
                if ($nextName !== ($current['name'] ?? '')) {
                    $dup = $this->sendCommand($client, '/ppp/profile/print', ['?name' => $nextName]);
                    if (! empty($dup)) {
                        throw new RuntimeException("Ya existe un perfil con nombre \"{$nextName}\".");
                    }
                }
            }

            $payload = $this->buildProfilePayload($data);
            $payload['.id'] = $current['.id'] ?? $profileId;

            $this->sendCommand($client, '/ppp/profile/set', $payload);

            $updated = $this->findProfile($client, $current['.id'] ?? $profileId);
            return $updated ? $this->mapProfile($updated) : $this->mapProfile($current);
        });
    }

    public function deleteProfile(int $routerId, string $profileId): array
    {
        return $this->withRouter($routerId, 'deleteProfile', function (MikrotikRawClient $client) use ($profileId) {
            $current = $this->findProfile($client, $profileId);
            if (! $current) {
                throw new RuntimeException('Perfil no encontrado en el router.');
            }

            $profileName = $current['name'] ?? '';

            if (in_array($profileName, ['default', 'default-encryption'])) {
                throw new RuntimeException('No se permite eliminar perfiles por defecto.');
            }

            // Check if profile is in use
            $secrets = $this->sendCommand($client, '/ppp/secret/print', ['?profile' => $profileName]);
            if (! empty($secrets)) {
                throw new RuntimeException("No se puede eliminar: el perfil \"{$profileName}\" está en uso por " . count($secrets) . ' usuario(s) PPP.');
            }

            $this->sendCommand($client, '/ppp/profile/remove', ['.id' => $current['.id'] ?? $profileId]);

            return ['ok' => true, 'message' => "Perfil \"{$profileName}\" eliminado correctamente."];
        });
    }

    /* ════════════════════════════════════════════════════════
     |  6. PPP SECRETS — Read-only list
     ════════════════════════════════════════════════════════ */

    public function getSecrets(int $routerId): array
    {
        $cacheKey = "mikrotik:secrets:{$routerId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $secrets = $this->withRouter($routerId, 'getSecrets', function (MikrotikRawClient $client) {
            $rows = $this->sendCommand($client, '/ppp/secret/print');

            return array_map(fn ($r) => [
                'id'            => $r['.id'] ?? '',
                'name'          => $r['name'] ?? '',
                'profile'       => $r['profile'] ?? null,
                'service'       => $r['service'] ?? null,
                'remoteAddress' => $r['remote-address'] ?? null,
                'comment'       => $r['comment'] ?? null,
                'disabled'      => ($r['disabled'] ?? 'false') === 'true',
                'lastLoggedOut' => $r['last-logged-out'] ?? null,
            ], $rows);
        });

        usort($secrets, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        Cache::put($cacheKey, $secrets, self::CACHE_TTL);

        return $secrets;
    }

    /* ════════════════════════════════════════════════════════
     |  7. PPPoE User operations (multi-router)
     ════════════════════════════════════════════════════════ */

    public function createPppoeUser(int $routerId, string $username, string $password, string $profile, ?string $comment = null): void
    {
        $this->withRouter($routerId, 'createPppoeUser', function (MikrotikRawClient $client) use ($username, $password, $profile, $comment) {
            $existing = $this->sendCommand($client, '/ppp/secret/print', ['?name' => $username]);
            if (! empty($existing)) {
                Log::warning("[MikrotikISP] User \"{$username}\" already exists — skipping creation");
                return;
            }

            $this->sendCommand($client, '/ppp/secret/add', [
                'name'     => $username,
                'password' => $password,
                'profile'  => $profile,
                'service'  => 'pppoe',
                'comment'  => $comment ?: "GO Ventas - {$username}",
            ]);
        });

        $this->invalidateCache($routerId);
    }

    public function suspendUser(int $routerId, string $username): void
    {
        $this->withRouter($routerId, 'suspendUser', function (MikrotikRawClient $client) use ($username) {
            $secrets = $this->sendCommand($client, '/ppp/secret/print', ['?name' => $username]);
            if (empty($secrets)) {
                Log::warning("[MikrotikISP] User \"{$username}\" not found on router");
                return;
            }

            $this->sendCommand($client, '/ppp/secret/set', [
                '.id'      => $secrets[0]['.id'],
                'disabled' => 'true',
            ]);

            $this->disconnectActiveSession($client, $username);
        });

        $this->invalidateCache($routerId);
    }

    public function activateUser(int $routerId, string $username): void
    {
        $this->withRouter($routerId, 'activateUser', function (MikrotikRawClient $client) use ($username) {
            $secrets = $this->sendCommand($client, '/ppp/secret/print', ['?name' => $username]);
            if (empty($secrets)) {
                Log::warning("[MikrotikISP] User \"{$username}\" not found on router");
                return;
            }

            $this->sendCommand($client, '/ppp/secret/set', [
                '.id'      => $secrets[0]['.id'],
                'disabled' => 'false',
            ]);
        });

        $this->invalidateCache($routerId);
    }

    public function changePlan(int $routerId, string $username, string $newProfile): void
    {
        $this->withRouter($routerId, 'changePlan', function (MikrotikRawClient $client) use ($username, $newProfile) {
            $secrets = $this->sendCommand($client, '/ppp/secret/print', ['?name' => $username]);
            if (empty($secrets)) {
                throw new RuntimeException("PPPoE user \"{$username}\" no encontrado en el router.");
            }

            $this->sendCommand($client, '/ppp/secret/set', [
                '.id'     => $secrets[0]['.id'],
                'profile' => $newProfile,
            ]);

            $this->disconnectActiveSession($client, $username);
        });

        $this->invalidateCache($routerId);
    }

    public function deleteUser(int $routerId, string $username): void
    {
        $this->withRouter($routerId, 'deleteUser', function (MikrotikRawClient $client) use ($username) {
            $secrets = $this->sendCommand($client, '/ppp/secret/print', ['?name' => $username]);
            if (empty($secrets)) {
                Log::warning("[MikrotikISP] User \"{$username}\" not found — skipping delete");
                return;
            }

            $this->disconnectActiveSession($client, $username);

            $this->sendCommand($client, '/ppp/secret/remove', ['.id' => $secrets[0]['.id']]);
        });

        $this->invalidateCache($routerId);
    }

    /* ────────────────────────────────────────────────────────
     |  Private helpers
     ──────────────────────────────────────────────────────── */

    private function disconnectActiveSession(MikrotikRawClient $client, string $username): void
    {
        try {
            $active = $this->sendCommand($client, '/ppp/active/print', ['?name' => $username]);
            if (! empty($active) && isset($active[0]['.id'])) {
                $this->sendCommand($client, '/ppp/active/remove', ['.id' => $active[0]['.id']]);
                Log::info("[MikrotikISP] Kicked session for \"{$username}\"");
            }
        } catch (\Throwable $e) {
            Log::warning("[MikrotikISP] Could not kick \"{$username}\": {$e->getMessage()}");
        }
    }

    private function findProfile(MikrotikRawClient $client, string $ref): ?array
    {
        $ref = urldecode(trim($ref));
        if (empty($ref)) {
            return null;
        }

        $byId = $this->sendCommand($client, '/ppp/profile/print', ['?.id' => $ref]);
        if (! empty($byId)) {
            return $byId[0];
        }

        $byName = $this->sendCommand($client, '/ppp/profile/print', ['?name' => $ref]);
        return ! empty($byName) ? $byName[0] : null;
    }

    private function buildProfilePayload(array $data): array
    {
        $payload = [];
        $map = [
            'name'          => 'name',
            'rateLimit'     => 'rate-limit',
            'rate_limit'    => 'rate-limit',
            'localAddress'  => 'local-address',
            'local_address' => 'local-address',
            'remoteAddress' => 'remote-address',
            'remote_address'=> 'remote-address',
            'dnsServer'     => 'dns-server',
            'dns_server'    => 'dns-server',
            'comment'       => 'comment',
        ];

        foreach ($map as $input => $routerKey) {
            if (isset($data[$input]) && $data[$input] !== '') {
                $payload[$routerKey] = trim($data[$input]);
            }
        }

        return $payload;
    }

    private function mapProfile(array $r): array
    {
        $name = $r['name'] ?? '';
        $rateLimit = $r['rate-limit'] ?? null;
        $parsed = $this->parseRateLimit($rateLimit);

        $isDefault = in_array($name, ['default', 'default-encryption']);

        return [
            'id'             => $r['.id'] ?? $name,
            'name'           => $name,
            'localAddress'   => $r['local-address'] ?? null,
            'remoteAddress'  => $r['remote-address'] ?? null,
            'rateLimit'      => $rateLimit,
            'dnsServer'      => $r['dns-server'] ?? null,
            'comment'        => $r['comment'] ?? null,
            'isDefault'      => $isDefault,
            'parsedUpMbps'   => $parsed['up'] ?? null,
            'parsedDownMbps' => $parsed['down'] ?? null,
        ];
    }

    private function parseRateLimit(?string $rateLimit): array
    {
        if (! $rateLimit) {
            return [];
        }

        $firstToken = explode(' ', trim($rateLimit))[0] ?? '';
        [$upRaw, $downRaw] = array_pad(explode('/', $firstToken), 2, null);

        $parse = function (?string $value): ?int {
            if (! $value || ! preg_match('/^(\d+(?:\.\d+)?)([KMG])?$/i', $value, $m)) {
                return null;
            }
            $n = (float) $m[1];
            $unit = strtoupper($m[2] ?? 'M');
            return match ($unit) {
                'G' => (int) round($n * 1000),
                'K' => max(1, (int) round($n / 1000)),
                default => (int) round($n),
            };
        };

        return [
            'up'   => $parse($upRaw),
            'down' => $parse($downRaw),
        ];
    }

    private function invalidateCache(int $routerId): void
    {
        Cache::forget("mikrotik:secrets:{$routerId}");
        Cache::forget("mikrotik:morosos:{$routerId}");
    }

    /**
     * Whether a profile is commercial (not a default MikroTik profile).
     */
    private function isCommercialProfile(string $name): bool
    {
        return ! in_array(mb_strtolower($name), ['default', 'default-encryption']);
    }

    /* ════════════════════════════════════════════════════════
     |  8. IP POOLS — List & Availability
     ════════════════════════════════════════════════════════ */

    public function getPools(int $routerId): array
    {
        return $this->withRouter($routerId, 'getPools', function (MikrotikRawClient $client) {
            $rows = $this->sendCommand($client, '/ip/pool/print');

            return array_map(fn ($r) => [
                'id'     => $r['.id'] ?? '',
                'name'   => $r['name'] ?? '',
                'ranges' => $r['ranges'] ?? '',
            ], $rows);
        });
    }

    public function getPoolAvailability(int $routerId): array
    {
        return $this->withRouter($routerId, 'getPoolAvailability', function (MikrotikRawClient $client) {
            $pools   = $this->sendCommand($client, '/ip/pool/print');
            $used    = $this->sendCommand($client, '/ip/pool/used/print');
            $secrets = $this->sendCommand($client, '/ppp/secret/print');

            // Collect all used IPs
            $usedIps = [];
            foreach ($used as $u) {
                if (! empty($u['address'])) {
                    $usedIps[] = $u['address'];
                }
            }
            foreach ($secrets as $s) {
                if (! empty($s['remote-address'])) {
                    $usedIps[] = $s['remote-address'];
                }
            }

            $result = [];
            foreach ($pools as $pool) {
                $name   = $pool['name'] ?? '';
                $ranges = $pool['ranges'] ?? '';
                $total  = $this->countIpsInRanges($ranges);

                $usedInPool = 0;
                foreach ($usedIps as $ip) {
                    if ($this->ipInRanges($ip, $ranges)) {
                        $usedInPool++;
                    }
                }

                $result[] = [
                    'name'   => $name,
                    'ranges' => $ranges,
                    'total'  => $total,
                    'used'   => $usedInPool,
                    'free'   => max(0, $total - $usedInPool),
                ];
            }

            return $result;
        });
    }

    /* ── IP math helpers ─────────────────────────────────── */

    private function ipToInt(string $ip): int
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return 0;
        }
        return (int) ip2long($ip);
    }

    private function countIpsInRanges(string $ranges): int
    {
        $total = 0;
        foreach (explode(',', $ranges) as $range) {
            $range = trim($range);
            if (empty($range)) continue;

            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                $s = ip2long(trim($start));
                $e = ip2long(trim($end));
                if ($s !== false && $e !== false) {
                    $total += abs($e - $s) + 1;
                }
            } else {
                $total += 1;
            }
        }
        return $total;
    }

    private function ipInRanges(string $ip, string $ranges): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (explode(',', $ranges) as $range) {
            $range = trim($range);
            if (empty($range)) continue;

            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                $s = ip2long(trim($start));
                $e = ip2long(trim($end));
                if ($s !== false && $e !== false && $ipLong >= min($s, $e) && $ipLong <= max($s, $e)) {
                    return true;
                }
            } else {
                if ($ipLong === ip2long(trim($range))) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ════════════════════════════════════════════════════════
     |  9. PPPoE AUTO-PROVISIONING
     ════════════════════════════════════════════════════════ */

    public function generatePppoeUsername(Client $client): string
    {
        $base = mb_strtolower(preg_replace('/\s+/', '.', trim($client->nombres)));
        $apellido = mb_strtolower(preg_replace('/\s+/', '.', trim($client->apellidos)));
        return "{$base}.{$apellido}";
    }

    public function generatePppoePassword(int $length = 10): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    public function provisionPppoe(Client $client, int $routerId): array
    {
        if (empty($client->plan)) {
            throw new RuntimeException('El cliente no tiene plan asignado.');
        }

        $username = $client->mikrotik_user ?: $this->generatePppoeUsername($client);
        $password = $client->mikrotik_password ?: $this->generatePppoePassword();
        $profile  = $client->mikrotik_profile ?: $client->plan->nombre;

        $this->createPppoeUser(
            $routerId,
            $username,
            $password,
            $profile,
            "{$client->apellidos}, {$client->nombres}"
        );

        $client->updateQuietly([
            'mikrotik_user'      => $username,
            'mikrotik_password'  => $password,
            'mikrotik_profile'   => $profile,
            'mikrotik_router_id' => $routerId,
            'service_status'     => 'activo',
        ]);

        return [
            'username' => $username,
            'profile'  => $profile,
            'router'   => $routerId,
        ];
    }

    /* ════════════════════════════════════════════════════════
     |  10. MOROSOS POR VENDEDORA
     ════════════════════════════════════════════════════════ */

    public function countMoresosByVendedora(): array
    {
        return Client::where('service_status', 'suspendido')
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->with('vendedora:id,name')
            ->get()
            ->map(fn ($row) => [
                'user_id'   => $row->user_id,
                'vendedora' => $row->vendedora->name ?? 'N/A',
                'morosos'   => $row->total,
            ])
            ->toArray();
    }

    /* ════════════════════════════════════════════════════════
     |  11. CACHE PRE-WARMING
     ════════════════════════════════════════════════════════ */

    public function preWarmCache(): void
    {
        $routers = MikrotikRouter::where('is_active', true)->get();

        foreach ($routers as $router) {
            try {
                $this->getSecrets($router->id);
                $this->getMoresoFromRouter($router->id);
                Log::info("[MikrotikISP:preWarm] Cache warmed for router \"{$router->name}\"");
            } catch (\Throwable $e) {
                Log::warning("[MikrotikISP:preWarm] Failed for \"{$router->name}\": {$e->getMessage()}");
            }
        }
    }
}
