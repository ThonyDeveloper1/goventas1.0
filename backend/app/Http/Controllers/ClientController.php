<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\Installation;
use App\Models\MikrotikRouter;
use App\Models\Supervision;
use App\Services\FraudDetectionService;
use App\Services\MikrotikIspService;
use App\Services\ReniecService;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /* ─────────────────────────────────────────────────────────
     |  GET /clients
     |  Returns paginated list scoped by role, with filters.
     ────────────────────────────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $this->maybeAutoReconcileMorosos($request->boolean('refresh_mikrotik'));

        $user = $request->user();

        $query = Client::query()
            ->with([
                'vendedora:id,name',
                'plan:id,nombre,velocidad_bajada,velocidad_subida,precio',
                'latestInstallation',
            ])
            ->forUser($user);

        // ── Filters ──────────────────────────────────────────
        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($estado = $request->input('estado')) {
            if ($estado === 'finalizada') {
                $query->where(function ($q) {
                    $q->whereIn('estado', ['activo', 'finalizada']);
                });
            } elseif ($estado === 'pre_registro') {
                $query->where(function ($q) {
                    $q->where('estado', 'pre_registro')
                        ->orWhereNull('estado');
                });
            } else {
                $query->estado($estado);
            }
        }

        if ($userId = $request->input('user_id')) {
            if ($user->isAdmin()) {
                $query->where('user_id', $userId);
            }
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', "{$to} 23:59:59");
        }

        // ── Sort ─────────────────────────────────────────────
        $sortBy  = in_array($request->input('sort_by'), ['nombres', 'apellidos', 'dni', 'created_at', 'estado'])
                   ? $request->input('sort_by', 'created_at')
                   : 'created_at';
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $clients = $query->paginate($request->input('per_page', 15));
        $mikrotikSnapshot = $this->buildMikrotikSnapshotMap($clients->getCollection());

        // Keep list payload light; photos are loaded on demand in client detail.
        $clients->getCollection()->each(function (Client $c) use ($mikrotikSnapshot) {
            $c->append('nombre_completo');

            $c->setAttribute('estado_comercial', $this->resolveCommercialState($c));

            $state = $mikrotikSnapshot[$c->id] ?? ['status' => 'sin_datos', 'ip' => null];
            $c->setAttribute('mikrotik_estado', $state['status']);
            $c->setAttribute('mikrotik_ip', $state['ip']);
        });

        return response()->json($clients);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /clients
     ────────────────────────────────────────────────────────── */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $installDate = $data['installacion_fecha'] ?? null;
        $installStart = $data['installacion_hora_inicio'] ?? null;
        $installDuration = (int) ($data['installacion_duracion'] ?? 0);

        unset($data['installacion_fecha'], $data['installacion_hora_inicio'], $data['installacion_duracion']);

        DB::beginTransaction();
        try {
            $client = Client::create([
                ...$data,
                'user_id' => $request->user()->isAdmin()
                    ? ($data['user_id'] ?? $request->user()->id)
                    : $request->user()->id,
                'estado'  => 'pre_registro',
            ]);

            if ($installDate && $installStart && in_array($installDuration, [1, 2], true)) {
                $schedule = app(ScheduleService::class);
                $horaFin = Installation::calcularHoraFin($installStart, $installDuration);

                if ($error = $schedule->validateSlot($installStart, $installDuration)) {
                    throw ValidationException::withMessages([
                        'installacion_hora_inicio' => [$error],
                    ]);
                }

                if ($schedule->hasConflict($installDate, $installStart, $horaFin)) {
                    throw ValidationException::withMessages([
                        'installacion_hora_inicio' => [
                            "El horario {$installStart}–{$horaFin} ya está ocupado para instalación.",
                        ],
                    ]);
                }

                Installation::create([
                    'client_id' => $client->id,
                    'user_id' => $client->user_id,
                    'fecha' => $installDate,
                    'hora_inicio' => $installStart,
                    'hora_fin' => $horaFin,
                    'duracion' => $installDuration,
                    'estado' => 'pendiente',
                ]);
            }

            $this->storeRequestPhotos($client, $request);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // ── Fraud analysis on new client ─────────────────────
        app(FraudDetectionService::class)->analyzeClient($client);

        // ── Sync with MikroTik to get IP and status ──────────
        try {
            $this->syncClientWithMikrotik($client);
        } catch (\Throwable $e) {
            Log::warning('ClientController: fallo al sincronizar cliente nuevo con MikroTik.', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        $client->refresh();
        $client->load([
            'vendedora:id,name',
            'photos',
            'plan:id,nombre,velocidad_bajada,velocidad_subida,precio',
            'latestInstallation',
        ]);
        $client->photos->each(fn ($p) => $p->append('url'));
        $client->append('nombre_completo');

        // ── Attach MikroTik state ────────────────────────────
        $mikrotikSnapshot = $this->buildMikrotikSnapshotMap(collect([$client]));
        $state = $mikrotikSnapshot[$client->id] ?? ['status' => 'sin_datos', 'ip' => null];
        $client->setAttribute('estado_comercial', $this->resolveCommercialState($client));
        $client->setAttribute('mikrotik_estado', $state['status']);
        $client->setAttribute('mikrotik_ip', $state['ip']);

        return response()->json([
            'success' => true,
            'message' => 'Cliente registrado correctamente.',
            'data'    => $client,
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /clients/{client}
     ────────────────────────────────────────────────────────── */
    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorizeAccess($request, $client);

        $client->load([
            'vendedora:id,name',
            'photos',
            'plan:id,nombre,velocidad_bajada,velocidad_subida,precio',
            'latestInstallation',
        ]);
        $client->photos->each(fn ($p) => $p->append('url'));
        $client->append('nombre_completo');

        return response()->json($client);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /clients/mikrotik-statuses
     |  Returns only MikroTik state/IP for visible clients.
     ────────────────────────────────────────────────────────── */
    public function mikrotikStatuses(Request $request): JsonResponse
    {
        $this->maybeAutoReconcileMorosos($request->boolean('refresh_mikrotik'));

        $user = $request->user();
        $ids = collect(explode(',', (string) $request->input('ids', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $clients = Client::query()
            ->select('id', 'nombres', 'apellidos', 'mikrotik_user', 'ip_address')
            ->whereIn('id', $ids)
            ->forUser($user)
            ->get();

        $snapshot = $this->buildMikrotikSnapshotMap($clients);

        $data = $clients->map(function (Client $client) use ($snapshot) {
            $state = $snapshot[$client->id] ?? ['status' => 'sin_datos', 'ip' => null];

            return [
                'id' => $client->id,
                'mikrotik_estado' => $state['status'] ?? 'sin_datos',
                'mikrotik_ip' => $state['ip'] ?? null,
                'ip_address' => $client->ip_address,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /* ─────────────────────────────────────────────────────────
     |  PUT /clients/{client}
     ────────────────────────────────────────────────────────── */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $this->authorizeAccess($request, $client);

        $data = $request->validated();
        $installDate = $data['installacion_fecha'] ?? null;
        $installStart = $data['installacion_hora_inicio'] ?? null;
        $installDuration = (int) ($data['installacion_duracion'] ?? 0);
        unset($data['installacion_fecha'], $data['installacion_hora_inicio'], $data['installacion_duracion']);

        DB::beginTransaction();
        try {
            $client->update($data);

            if ($installDate && $installStart && in_array($installDuration, [1, 2], true)) {
                $schedule = app(ScheduleService::class);
                $horaFin = Installation::calcularHoraFin($installStart, $installDuration);

                if ($error = $schedule->validateSlot($installStart, $installDuration)) {
                    throw ValidationException::withMessages([
                        'installacion_hora_inicio' => [$error],
                    ]);
                }

                $latestInstallation = $client->latestInstallation()->first();
                $excludeId = $latestInstallation?->id;
                if ($schedule->hasConflict($installDate, $installStart, $horaFin, $excludeId)) {
                    throw ValidationException::withMessages([
                        'installacion_hora_inicio' => [
                            "El horario {$installStart}–{$horaFin} ya está ocupado para instalación.",
                        ],
                    ]);
                }

                if ($latestInstallation) {
                    $latestInstallation->update([
                        'fecha' => $installDate,
                        'hora_inicio' => $installStart,
                        'hora_fin' => $horaFin,
                        'duracion' => $installDuration,
                        'estado' => $latestInstallation->estado ?: 'pendiente',
                    ]);
                } else {
                    Installation::create([
                        'client_id' => $client->id,
                        'user_id' => $client->user_id,
                        'fecha' => $installDate,
                        'hora_inicio' => $installStart,
                        'hora_fin' => $horaFin,
                        'duracion' => $installDuration,
                        'estado' => 'pendiente',
                    ]);
                }
            }

            $this->storeRequestPhotos($client, $request);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // ── Re-analyze fraud ─────────────────────────────
        app(FraudDetectionService::class)->analyzeClient($client);
        $client->refresh();

        $client->load([
            'vendedora:id,name',
            'photos',
            'plan:id,nombre,velocidad_bajada,velocidad_subida,precio',
            'latestInstallation',
        ]);
        $client->photos->each(fn ($p) => $p->append('url'));
        $client->append('nombre_completo');

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado correctamente.',
            'data'    => $client,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  PATCH /clients/{client}/status (admin only)
     ────────────────────────────────────────────────────────── */
    public function updateStatus(Request $request, Client $client): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede cambiar el estado del cliente.',
            ], 403);
        }

        $validated = $request->validate([
            'estado' => ['required', 'in:pre_registro,en_proceso,finalizada,suspendido,baja'],
        ]);

        $client->update([
            'estado' => $validated['estado'],
        ]);

        $client->refresh();
        $client->append('nombre_completo');
        $client->setAttribute('estado_comercial', $this->resolveCommercialState($client));

        return response()->json([
            'success' => true,
            'message' => 'Estado del cliente actualizado correctamente.',
            'data' => $client,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  DELETE /clients/{client}
     ────────────────────────────────────────────────────────── */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede eliminar clientes.',
            ], 403);
        }

        try {
            $cleanup = [
                'installations' => 0,
                'supervisions' => 0,
                'supervision_photos' => 0,
            ];

            DB::beginTransaction();

            // Remove all client evidence photos from storage before DB delete.
            foreach ($client->photos as $photo) {
                Storage::disk('public')->delete($photo->photo_path);
            }

            // Find related installations and supervisions that block FK delete.
            $installationIds = Installation::where('client_id', $client->id)->pluck('id')->all();

            if (! empty($installationIds)) {
                $supervisions = Supervision::whereIn('installation_id', $installationIds)
                    ->with('photos')
                    ->get();

                foreach ($supervisions as $supervision) {
                    foreach ($supervision->photos as $photo) {
                        Storage::disk('public')->delete($photo->photo_path);
                        $cleanup['supervision_photos']++;
                    }
                }

                $cleanup['supervisions'] = Supervision::whereIn('installation_id', $installationIds)->count();
                Supervision::whereIn('installation_id', $installationIds)->delete();

                $cleanup['installations'] = Installation::whereIn('id', $installationIds)->count();
                Installation::whereIn('id', $installationIds)->delete();
            }

            $client->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cliente eliminado correctamente.',
                'cleanup' => $cleanup,
            ]);
        } catch (QueryException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar este cliente porque tiene registros relacionados (instalaciones/supervisiones).',
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar el cliente. Intenta nuevamente.',
            ], 500);
        }
    }

    /* ─────────────────────────────────────────────────────────
     |  DELETE /clients/{client}/photos/{photo}
     ────────────────────────────────────────────────────────── */
    public function destroyPhoto(Request $request, Client $client, ClientPhoto $photo): JsonResponse
    {
        $this->authorizeAccess($request, $client);

        if ($photo->client_id !== $client->id) {
            return response()->json(['message' => 'Foto no encontrada.'], 404);
        }

        if ($client->photos()->count() <= 1) {
            return response()->json([
                'message' => 'El cliente debe conservar al menos una foto de evidencia.',
            ], 422);
        }

        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();

        return response()->json(['success' => true]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /reniec/{dni}
     ────────────────────────────────────────────────────────── */
    public function reniec(string $dni, ReniecService $reniec): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['message' => 'DNI inválido.'], 422);
        }

        $data = $reniec->getPersonByDni($dni);

        if (! $data) {
            return response()->json(['message' => 'DNI no encontrado.'], 404);
        }

        return response()->json($data);
    }

    /* ─── Private helpers ───────────────────────────────────── */

    private function authorizeAccess(Request $request, Client $client): void
    {
        $user = $request->user();
        if ($user->isVendedora() && $client->user_id !== $user->id) {
            abort(403, 'No tienes permiso para acceder a este cliente.');
        }
    }

    private function buildMikrotikSnapshotMap($clients): array
    {
        $map = [];
        foreach ($clients as $c) {
            $map[$c->id] = ['status' => 'sin_datos', 'ip' => null];
        }

        if (empty($map)) {
            return $map;
        }

        $routerId = MikrotikRouter::where('is_active', true)->value('id');
        if (! $routerId) {
            return $map;
        }

        try {
            $snapshot = app(MikrotikIspService::class)->buildClientMikrotikSnapshot($routerId, $clients);
            foreach ($snapshot as $clientId => $state) {
                $map[$clientId] = [
                    'status' => $state['status'] ?? 'sin_datos',
                    'ip' => $state['ip'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('ClientController: fallo al construir snapshot MikroTik para listado.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $map;
    }

    /**
     * Auto-reconcile moroso/activo in request path with safety guards.
     * This complements scheduled jobs and avoids stale state if scheduler lags.
     */
    private function maybeAutoReconcileMorosos(bool $force = false): void
    {
        $throttleSeconds = 10;
        $lastRunKey = 'mikrotik:auto_reconcile:last_run';
        $lockKey = 'mikrotik:auto_reconcile:lock';

        if (! $force) {
            $lastRun = (int) Cache::get($lastRunKey, 0);
            if ($lastRun > 0 && (time() - $lastRun) < $throttleSeconds) {
                return;
            }
        }

        $lock = Cache::lock($lockKey, 20);
        if (! $lock->get()) {
            return;
        }

        try {
            $service = app(MikrotikIspService::class);
            $routerIds = MikrotikRouter::where('is_active', true)->pluck('id');

            foreach ($routerIds as $routerId) {
                try {
                    $service->syncMorososToDb((int) $routerId);
                } catch (\Throwable $e) {
                    Log::warning('ClientController: auto reconcile failed for router.', [
                        'router_id' => $routerId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Cache::put($lastRunKey, time(), $throttleSeconds * 2);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  \Illuminate\Http\UploadedFile[]  $files
     */
    private function storeRequestPhotos(Client $client, Request $request): void
    {
        if ($request->hasFile('fotos')) {
            $this->storePhotos($client, $request->file('fotos'), 'fachada');
        }

        if ($request->hasFile('fotos_fachada')) {
            $this->storePhotos($client, $request->file('fotos_fachada'), 'fachada');
        }

        if ($request->hasFile('fotos_dni')) {
            $this->storePhotos($client, $request->file('fotos_dni'), 'dni');
        }
    }

    /**
     * @param  \\Illuminate\\Http\\UploadedFile[]  $files
     */
    private function storePhotos(Client $client, array $files, string $photoType = 'fachada'): void
    {
        foreach ($files as $file) {
            $path = $file->store("clients/{$client->id}/photos", 'public');
            $client->photos()->create([
                'photo_path' => $path,
                'photo_type' => $photoType,
            ]);
        }
    }

    private function resolveCommercialState(Client $client): string
    {
        if ($client->estado === 'baja') {
            return 'baja';
        }

        if ($client->estado === 'suspendido') {
            return 'suspendido';
        }

        if ($client->estado === 'pre_registro') {
            return 'pre_registro';
        }

        if ($client->estado === 'en_proceso') {
            return 'en_proceso';
        }

        if ($client->estado === 'finalizada' || $client->estado === 'activo') {
            return 'finalizada';
        }

        return 'pre_registro';
    }

    /**
     * Sync a single client with MikroTik to get IP and status.
     */
    private function syncClientWithMikrotik(Client $client): void
    {
        $routerId = MikrotikRouter::where('is_active', true)->value('id');
        if (! $routerId) {
            return;
        }

        try {
            $service = app(MikrotikIspService::class);
            $snapshot = $service->buildClientMikrotikSnapshot($routerId, collect([$client]));

            if (isset($snapshot[$client->id])) {
                $state = $snapshot[$client->id];
                $changes = [];

                // Persist discovered IP in the real DB column.
                if (! empty($state['ip'])) {
                    $changes['ip_address'] = $state['ip'];
                    $changes['mikrotik_router_id'] = $routerId;
                }

                // Don't override estado here — this endpoint only enriches network data.
                if (! empty($changes)) {
                    $client->update($changes);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('syncClientWithMikrotik: erro al sincronizar cliente con MikroTik.', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
