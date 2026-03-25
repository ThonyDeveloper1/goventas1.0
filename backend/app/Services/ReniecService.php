<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReniecService
{
    public function getPersonByDni(string $dni): array
    {
        $token = Setting::get('reniec_token', '') ?: config('services.reniec.token', '');
        $url = config('services.reniec.url', 'https://apiperu.dev/api');
        $enabled = Setting::get('reniec_enabled', '0') === '1';

        if ($token && $enabled) {
            return Cache::remember("reniec:{$dni}", 3600, function () use ($token, $url, $dni) {
                // If RENIEC is enabled, never fabricate identity data.
                // Return empty payload when provider fails or has no match.
                return $this->fetchFromApi($token, $url, $dni) ?? [];
            });
        }

        return $this->mock($dni);
    }

    private function fetchFromApi(string $token, string $url, string $dni): ?array
    {
        try {
            $http = Http::withToken($token)
                ->accept('application/json')
                ->timeout(8)
                ->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException);

            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post("{$url}/dni", ['dni' => $dni]);

            if ($response->status() === 401) {
                Log::warning('ReniecService: token invalido, verifica RENIEC_API_TOKEN en .env.', [
                    'dni' => $dni,
                ]);

                return null;
            }

            if ($response->successful() && $response->json('success')) {
                $data = $response->json('data') ?? [];

                Log::info('ReniecService: DNI consultado.', [
                    'dni' => $dni,
                ]);

                return [
                    'nombres' => $data['nombres'] ?? '',
                    'apellidos' => trim(
                        ($data['apellido_paterno'] ?? $data['apellidoPaterno'] ?? '') . ' ' .
                        ($data['apellido_materno'] ?? $data['apellidoMaterno'] ?? '')
                    ),
                ];
            }

            Log::warning('ReniecService: respuesta inesperada de apiperu.dev.', [
                'dni' => $dni,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('ReniecService: excepcion al consultar DNI.', [
                'dni' => $dni,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function mock(string $dni): array
    {
        $names = [
            'CARLOS ALBERTO', 'MARIA ELENA', 'JUAN PABLO', 'ANA LUCIA',
            'PEDRO ANTONIO', 'ROSA ISABEL', 'LUIS MIGUEL', 'CARMEN ROSA',
            'JORGE ENRIQUE', 'PATRICIA VANESSA', 'ROBERTO CARLOS', 'SILVIA BEATRIZ',
        ];

        $lastnames = [
            'GARCIA LOPEZ', 'RODRIGUEZ TORRES', 'MARTINEZ FLORES', 'HERNANDEZ DIAZ',
            'QUISPE MAMANI', 'CONDORI HUANCA', 'FLORES RAMOS', 'GUTIERREZ VARGAS',
            'SANTOS REYES', 'MENDOZA SALINAS', 'CASTRO VEGA', 'ORTEGA LUNA',
        ];

        $seed = (int) substr($dni, 0, 3);

        return [
            'nombres' => $names[$seed % count($names)],
            'apellidos' => $lastnames[$seed % count($lastnames)],
        ];
    }
}
