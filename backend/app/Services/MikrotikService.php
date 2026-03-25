<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;
use RuntimeException;

/**
 * MikroTik RouterOS API Service.
 *
 * Communicates with MikroTik routers via the RouterOS API (port 8728).
 * Falls back to a mock mode when MIKROTIK_HOST is not configured,
 * allowing development without a physical router.
 */
class MikrotikService
{
    private ?object $connection = null;
    private readonly string $host;
    private readonly int    $port;
    private readonly string $user;
    private readonly string $password;
    private readonly bool   $mock;

    private const CACHE_TTL = 300; // 5 minutes

    public function __construct()
    {
        $this->host     = config('mikrotik.host', '');
        $this->port     = (int) config('mikrotik.port', 8728);
        $this->user     = config('mikrotik.user', 'admin');
        $this->password = config('mikrotik.password', '');
        $this->mock     = empty($this->host);
    }

    /* ─── Connection ─────────────────────────────────────── */

    public function connect(): bool
    {
        if ($this->mock) {
            return true;
        }

        try {
            $this->connection = new Client([
                'host' => $this->host,
                'user' => $this->user,
                'pass' => $this->password,
                'port' => $this->port,
                'ssl'  => (bool) config('mikrotik.use_tls', false),
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('MikroTik connection failed', [
                'host'    => $this->host,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException("No se pudo conectar al router MikroTik: {$e->getMessage()}");
        }
    }

    public function disconnect(): void
    {
        $this->connection = null;
    }

    /* ─── PPPoE User Management ──────────────────────────── */

    /**
     * Create a PPPoE secret (user) on the router.
     */
    public function createUser(string $username, string $password, string $profile = 'default'): array
    {
        if ($this->mock) {
            return $this->mockResponse('created', $username, $profile);
        }

        $this->ensureConnected();

        $response = $this->sendCommand('/ppp/secret/add', [
            'name'     => $username,
            'password' => $password,
            'service'  => 'pppoe',
            'profile'  => $profile,
        ]);

        $this->clearUserCache($username);

        return [
            'success'  => true,
            'username' => $username,
            'profile'  => $profile,
        ];
    }

    /**
     * Enable (activate) a PPPoE user.
     */
    public function enableUser(string $username): array
    {
        if ($this->mock) {
            Cache::put("mikrotik:status:{$username}", 'activo', self::CACHE_TTL);
            return $this->mockResponse('enabled', $username);
        }

        $this->ensureConnected();

        $id = $this->findSecretId($username);

        if (! $id) {
            throw new RuntimeException("Usuario PPPoE '{$username}' no encontrado en MikroTik.");
        }

        $this->sendCommand('/ppp/secret/set', [
            '.id'      => $id,
            'disabled' => 'false',
        ]);

        // Also remove active connection to force reconnect with new profile
        $this->removeActiveConnection($username);

        $this->clearUserCache($username);

        return [
            'success'  => true,
            'username' => $username,
            'status'   => 'activo',
        ];
    }

    /**
     * Disable (suspend) a PPPoE user.
     */
    public function disableUser(string $username): array
    {
        if ($this->mock) {
            Cache::put("mikrotik:status:{$username}", 'suspendido', self::CACHE_TTL);
            return $this->mockResponse('disabled', $username);
        }

        $this->ensureConnected();

        $id = $this->findSecretId($username);

        if (! $id) {
            throw new RuntimeException("Usuario PPPoE '{$username}' no encontrado en MikroTik.");
        }

        $this->sendCommand('/ppp/secret/set', [
            '.id'      => $id,
            'disabled' => 'true',
        ]);

        // Remove active connection to disconnect immediately
        $this->removeActiveConnection($username);

        $this->clearUserCache($username);

        return [
            'success'  => true,
            'username' => $username,
            'status'   => 'suspendido',
        ];
    }

    /**
     * Remove a PPPoE user from the router.
     */
    public function removeUser(string $username): array
    {
        if ($this->mock) {
            Cache::forget("mikrotik:status:{$username}");
            return $this->mockResponse('removed', $username);
        }

        $this->ensureConnected();

        $id = $this->findSecretId($username);

        if ($id) {
            $this->removeActiveConnection($username);
            $this->sendCommand('/ppp/secret/remove', ['.id' => $id]);
        }

        $this->clearUserCache($username);

        return [
            'success'  => true,
            'username' => $username,
        ];
    }

    /**
     * Get the real status of a PPPoE user.
     */
    public function getUserStatus(string $username): array
    {
        $cacheKey = "mikrotik:status:{$username}";

        if ($this->mock) {
            $status = Cache::get($cacheKey, 'suspendido');
            return [
                'username' => $username,
                'status'   => $status,
                'online'   => $status === 'activo',
                'uptime'   => $status === 'activo' ? '02:34:12' : null,
                'address'  => $status === 'activo' ? '10.0.' . rand(0, 255) . '.' . rand(1, 254) : null,
                'mock'     => true,
            ];
        }

        // Try cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return is_array($cached) ? $cached : ['username' => $username, 'status' => $cached];
        }

        $this->ensureConnected();

        $secret = $this->findSecret($username);

        if (! $secret) {
            return [
                'username' => $username,
                'status'   => 'no_encontrado',
                'online'   => false,
            ];
        }

        $disabled = ($secret['disabled'] ?? 'false') === 'true';

        // Check if user has an active connection
        $active = $this->findActiveConnection($username);

        $result = [
            'username' => $username,
            'status'   => $disabled ? 'suspendido' : 'activo',
            'online'   => ! empty($active),
            'uptime'   => $active['uptime'] ?? null,
            'address'  => $active['address'] ?? null,
            'profile'  => $secret['profile'] ?? 'default',
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Get status for multiple users at once (batch).
     */
    public function getBatchStatus(array $usernames): array
    {
        if ($this->mock) {
            return array_map(fn ($u) => $this->getUserStatus($u), $usernames);
        }

        $this->ensureConnected();

        // Fetch all secrets and active connections in bulk
        $secrets = $this->sendCommand('/ppp/secret/print');
        $active  = $this->sendCommand('/ppp/active/print');

        $secretMap = [];
        foreach ($secrets as $s) {
            $secretMap[$s['name'] ?? ''] = $s;
        }

        $activeMap = [];
        foreach ($active as $a) {
            $activeMap[$a['name'] ?? ''] = $a;
        }

        $results = [];
        foreach ($usernames as $username) {
            $secret = $secretMap[$username] ?? null;
            $conn   = $activeMap[$username] ?? null;

            if (! $secret) {
                $results[] = [
                    'username' => $username,
                    'status'   => 'no_encontrado',
                    'online'   => false,
                ];
                continue;
            }

            $disabled = ($secret['disabled'] ?? 'false') === 'true';
            $result = [
                'username' => $username,
                'status'   => $disabled ? 'suspendido' : 'activo',
                'online'   => ! empty($conn),
                'uptime'   => $conn['uptime'] ?? null,
                'address'  => $conn['address'] ?? null,
                'profile'  => $secret['profile'] ?? 'default',
            ];

            Cache::put("mikrotik:status:{$username}", $result, self::CACHE_TTL);
            $results[] = $result;
        }

        return $results;
    }

    /* ─── Private helpers ────────────────────────────────── */

    private function ensureConnected(): void
    {
        if (! $this->connection) {
            $this->connect();
        }
    }

    private function findSecretId(string $username): ?string
    {
        $secret = $this->findSecret($username);
        return $secret['.id'] ?? null;
    }

    private function findSecret(string $username): ?array
    {
        $result = $this->sendCommand('/ppp/secret/print', [
            '?name' => $username,
        ]);

        return $result[0] ?? null;
    }

    private function findActiveConnection(string $username): ?array
    {
        $result = $this->sendCommand('/ppp/active/print', [
            '?name' => $username,
        ]);

        return $result[0] ?? null;
    }

    private function removeActiveConnection(string $username): void
    {
        $active = $this->findActiveConnection($username);
        if ($active && isset($active['.id'])) {
            $this->sendCommand('/ppp/active/remove', ['.id' => $active['.id']]);
        }
    }

    private function sendCommand(string $command, array $params = []): array
    {
        try {
            $query = new Query($command);

            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }

            $responses = $this->connection->query($query)->read();

            return is_array($responses) ? $responses : [];
        } catch (\Throwable $e) {
            Log::error('MikroTik command failed', [
                'command' => $command,
                'params'  => array_diff_key($params, array_flip(['password'])),
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException("Error en comando MikroTik: {$e->getMessage()}");
        }
    }

    private function clearUserCache(string $username): void
    {
        Cache::forget("mikrotik:status:{$username}");
    }

    private function mockResponse(string $action, string $username, ?string $profile = null): array
    {
        return [
            'success'  => true,
            'username' => $username,
            'action'   => $action,
            'profile'  => $profile,
            'mock'     => true,
        ];
    }
}
