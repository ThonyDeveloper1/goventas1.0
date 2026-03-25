<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;

class SettingsController extends Controller
{
    public function publicConfig(): JsonResponse
    {
        return response()->json([
            'reniec_enabled' => Setting::get('reniec_enabled', '0') === '1',
        ]);
    }

    public function index(): JsonResponse
    {
        $dbToken = Setting::get('reniec_token', '');
        $envToken = config('services.reniec.token', '');
        $configured = ! empty($dbToken) || ! empty($envToken);

        $updatedAt = null;
        if (! empty($dbToken)) {
            $updatedAt = Setting::where('key', 'reniec_token')->value('updated_at');
        }

        return response()->json([
            'reniec_configured' => $configured,
            'reniec_enabled' => Setting::get('reniec_enabled', '0') === '1',
            'reniec_updated_at' => $updatedAt,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reniec_enabled' => ['sometimes', 'boolean'],
            'reniec_token' => ['sometimes', 'nullable', 'string', 'max:600'],
        ]);

        if (array_key_exists('reniec_enabled', $validated)) {
            Setting::set('reniec_enabled', $validated['reniec_enabled'] ? '1' : '0');
        }

        if (! empty($validated['reniec_token'])) {
            Setting::set('reniec_token', $validated['reniec_token']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuracion guardada correctamente.',
        ]);
    }

    public function clearToken(): JsonResponse
    {
        Setting::set('reniec_token', '');
        cache()->forget('setting:reniec_token');

        return response()->json([
            'success' => true,
            'message' => 'Token eliminado correctamente.',
        ]);
    }

    public function testReniec(): JsonResponse
    {
        $token = Setting::get('reniec_token', '') ?: config('services.reniec.token', '');
        $url = config('services.reniec.url', 'https://apiperu.dev/api');

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No hay token configurado. Agrega uno desde esta pantalla o en .env del servidor.',
            ], 422);
        }

        try {
            $http = Http::withToken($token)
                ->accept('application/json')
                ->timeout(8);

            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post("{$url}/dni", ['dni' => '12345678']);

            if ($response->status() === 401) {
                Log::warning('SettingsController: test RENIEC token invalido.');

                return response()->json([
                    'success' => false,
                    'message' => 'Token invalido o sin permisos.',
                ]);
            }

            if ($response->successful()) {
                Log::info('SettingsController: test RENIEC exitoso.');

                return response()->json([
                    'success' => true,
                    'message' => 'Conexion exitosa con apiperu.dev.',
                ]);
            }

            $message = (string) ($response->json('message') ?? '');
            if ($message !== '' && str_contains(strtolower($message), 'no se encontraron resultados')) {
                Log::info('SettingsController: test RENIEC sin resultados para DNI de prueba, pero conexion/token validos.', [
                    'status' => $response->status(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Conexion exitosa con apiperu.dev. El DNI de prueba no tuvo resultados.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "Respuesta inesperada (HTTP {$response->status()}).",
            ]);
        } catch (\Throwable $e) {
            Log::error('SettingsController: testReniec exception.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar: ' . $e->getMessage(),
            ]);
        }
    }

    /* ─── MikroTik Routers CRUD ────────────────────────────────── */

    public function routers(): JsonResponse
    {
        $routers = MikrotikRouter::orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id'       => $r->id,
                'name'     => $r->name,
                'host'     => $r->host,
                'port'     => $r->port,
                'username' => $r->username,
                'use_tls'  => $r->use_tls,
                'is_active'=> $r->is_active,
            ]);

        return response()->json($routers);
    }

    public function storeRouter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'host'     => ['required', 'string', 'max:255'],
            'port'     => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'use_tls'  => ['boolean'],
        ]);

        $router = MikrotikRouter::create($validated);
        $router->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Router creado correctamente.',
            'router'  => [
                'id'       => $router->id,
                'name'     => $router->name,
                'host'     => $router->host,
                'port'     => $router->port,
                'username' => $router->username,
                'use_tls'  => $router->use_tls,
                'is_active'=> $router->is_active,
            ],
        ], 201);
    }

    public function updateRouter(Request $request, MikrotikRouter $router): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['sometimes', 'string', 'max:100'],
            'host'     => ['sometimes', 'string', 'max:255'],
            'port'     => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'string', 'max:100'],
            'password' => ['sometimes', 'string', 'max:255'],
            'use_tls'  => ['sometimes', 'boolean'],
        ]);

        $router->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Router actualizado correctamente.',
            'router'  => [
                'id'       => $router->id,
                'name'     => $router->name,
                'host'     => $router->host,
                'port'     => $router->port,
                'username' => $router->username,
                'use_tls'  => $router->use_tls,
                'is_active'=> $router->is_active,
            ],
        ]);
    }

    public function destroyRouter(MikrotikRouter $router): JsonResponse
    {
        $router->delete();

        return response()->json([
            'success' => true,
            'message' => 'Router eliminado correctamente.',
        ]);
    }

    public function testRouter(MikrotikRouter $router): JsonResponse
    {
        try {
            $client = new Client([
                'host' => $router->host,
                'user' => $router->username,
                'pass' => $router->decrypted_password,
                'port' => (int) $router->port,
                'ssl'  => (bool) $router->use_tls,
            ]);

            $response = $client->query(new Query('/system/identity/print'))->read();
            $identity = $response[0]['name'] ?? 'MikroTik';

            return response()->json([
                'success'  => true,
                'message'  => "Conexión exitosa. Router: {$identity}",
                'identity' => $identity,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MikroTik router test failed.', [
                'router_id' => $router->id,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar: ' . $e->getMessage(),
            ]);
        }
    }
}
