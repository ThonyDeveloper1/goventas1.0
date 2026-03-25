<?php

namespace App\Http\Controllers;

use App\Events\ClientServiceStatusChanged;
use App\Models\Client;
use App\Models\InternalNotification;
use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MikrotikController extends Controller
{
    public function __construct(private readonly MikrotikService $mikrotik) {}

    /* ─────────────────────────────────────────────────────────
     |  POST /mikrotik/activate/{client}
     |  Admin only
     ────────────────────────────────────────────────────────── */
    public function activate(Request $request, Client $client): JsonResponse
    {
        $this->ensureMikrotikUser($client);

        $previousStatus = $client->service_status;

        if ($previousStatus === 'activo') {
            return response()->json([
                'success' => false,
                'message' => 'El servicio ya está activo.',
            ], 422);
        }

        try {
            $this->mikrotik->enableUser($client->mikrotik_user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $client->update(['service_status' => 'activo']);

        event(new ClientServiceStatusChanged($client, $previousStatus, 'activo', 'activated'));

        return response()->json([
            'success' => true,
            'message' => "Servicio activado para {$client->nombre_completo}.",
            'data'    => $this->clientData($client),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /mikrotik/suspend/{client}
     |  Admin only
     ────────────────────────────────────────────────────────── */
    public function suspend(Request $request, Client $client): JsonResponse
    {
        $this->ensureMikrotikUser($client);

        $previousStatus = $client->service_status;

        if ($previousStatus === 'suspendido') {
            return response()->json([
                'success' => false,
                'message' => 'El servicio ya está suspendido.',
            ], 422);
        }

        try {
            $this->mikrotik->disableUser($client->mikrotik_user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $client->update(['service_status' => 'suspendido']);

        event(new ClientServiceStatusChanged($client, $previousStatus, 'suspendido', 'suspended'));

        return response()->json([
            'success' => true,
            'message' => "Servicio suspendido para {$client->nombre_completo}.",
            'data'    => $this->clientData($client),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /mikrotik/status/{client}
     ────────────────────────────────────────────────────────── */
    public function status(Client $client): JsonResponse
    {
        if (! $client->mikrotik_user) {
            return response()->json([
                'username'       => null,
                'status'         => $client->service_status,
                'online'         => false,
                'not_configured' => true,
            ]);
        }

        try {
            $routerStatus = $this->mikrotik->getUserStatus($client->mikrotik_user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'username' => $client->mikrotik_user,
                'status'   => $client->service_status,
                'online'   => false,
                'error'    => $e->getMessage(),
            ]);
        }

        return response()->json([
            'client_id'      => $client->id,
            'service_status' => $client->service_status,
            ...$routerStatus,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /mikrotik/provision/{client}
     |  Create PPPoE user on router
     ────────────────────────────────────────────────────────── */
    public function provision(Request $request, Client $client): JsonResponse
    {
        $request->validate([
            'mikrotik_user'     => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._\-]+$/'],
            'mikrotik_password' => ['required', 'string', 'min:6', 'max:50'],
            'mikrotik_profile'  => ['nullable', 'string', 'max:50'],
        ]);

        // Check username is unique within our DB
        $exists = Client::where('mikrotik_user', $request->mikrotik_user)
            ->where('id', '!=', $client->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'mikrotik_user' => ['Este usuario MikroTik ya está asignado a otro cliente.'],
            ]);
        }

        $profile = $request->mikrotik_profile ?: config('mikrotik.default_profile');

        try {
            $this->mikrotik->createUser(
                $request->mikrotik_user,
                $request->mikrotik_password,
                $profile,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $client->update([
            'mikrotik_user'     => $request->mikrotik_user,
            'mikrotik_password' => $request->mikrotik_password,
            'mikrotik_profile'  => $profile,
            'service_status'    => 'suspendido',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Usuario PPPoE creado: {$request->mikrotik_user}",
            'data'    => $this->clientData($client),
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /mikrotik/sync-all
     |  Admin only — synchronize all clients with MikroTik
     ────────────────────────────────────────────────────────── */
    public function syncAll(): JsonResponse
    {
        $clients = Client::whereNotNull('mikrotik_user')
            ->select('id', 'mikrotik_user', 'service_status')
            ->get();

        if ($clients->isEmpty()) {
            return response()->json([
                'success'   => true,
                'synced'    => 0,
                'message'   => 'No hay clientes con MikroTik configurado.',
            ]);
        }

        $usernames = $clients->pluck('mikrotik_user')->toArray();

        try {
            $statuses = $this->mikrotik->getBatchStatus($usernames);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        $statusMap = [];
        foreach ($statuses as $s) {
            $statusMap[$s['username']] = $s['status'] ?? 'suspendido';
        }

        $updated = 0;

        DB::transaction(function () use ($clients, $statusMap, &$updated) {
            foreach ($clients as $client) {
                $routerStatus = $statusMap[$client->mikrotik_user] ?? null;

                if ($routerStatus && $routerStatus !== 'no_encontrado' && $routerStatus !== $client->service_status) {
                    $previous = $client->service_status;
                    $client->update(['service_status' => $routerStatus]);
                    event(new ClientServiceStatusChanged($client, $previous, $routerStatus, 'synced'));
                    $updated++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'synced'  => $updated,
            'total'   => $clients->count(),
            'message' => "Sincronización completada. {$updated} cliente(s) actualizado(s).",
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /mikrotik/network-overview
     |  Real-time network summary
     ────────────────────────────────────────────────────────── */
    public function networkOverview(): JsonResponse
    {
        $stats = Cache::remember('mikrotik:network_overview', 60, function () {
            return [
                'total'      => Client::whereNotNull('mikrotik_user')->count(),
                'activos'    => Client::where('service_status', 'activo')->count(),
                'suspendidos'=> Client::where('service_status', 'suspendido')->count(),
                'cortados'   => Client::where('service_status', 'cortado')->count(),
                'sin_config' => Client::whereNull('mikrotik_user')->count(),
            ];
        });

        return response()->json($stats);
    }

    /* ─── Private ────────────────────────────────────────── */

    private function ensureMikrotikUser(Client $client): void
    {
        if (! $client->mikrotik_user) {
            throw ValidationException::withMessages([
                'mikrotik_user' => ['Este cliente no tiene usuario MikroTik configurado.'],
            ]);
        }
    }

    private function clientData(Client $client): array
    {
        return [
            'id'              => $client->id,
            'nombres'         => $client->nombres,
            'apellidos'       => $client->apellidos,
            'dni'             => $client->dni,
            'mikrotik_user'   => $client->mikrotik_user,
            'mikrotik_profile'=> $client->mikrotik_profile,
            'service_status'  => $client->service_status,
        ];
    }
}
