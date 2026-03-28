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
        $url = rtrim((string) (config('services.reniec.url') ?: 'https://apiperu.dev/api'), '/');
        $cacheKey = "reniec:{$dni}";

        // Si existe token, solo devolver resultados reales de la API.
        if ($token) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! empty($cached['nombres'])) {
                return $cached;
            }

            $result = $this->fetchFromApi($token, $url, $dni);
            if ($result) {
                Cache::put($cacheKey, $result, 3600);
                return $result;
            }

            Log::warning('ReniecService: API RENIEC no devolvio datos reales.', ['dni' => $dni]);
            return [];
        }

        // Si no hay token, usa datos simulados
        return $this->mock($dni);
    }

    private function fetchFromApi(string $token, string $url, string $dni): ?array
    {
        try {
            $baseHttp = Http::accept('application/json')
                ->timeout(8)
                ->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException);

            if (app()->environment('local')) {
                $baseHttp = $baseHttp->withoutVerifying();
            }

            Log::info('ReniecService: Consultando DNI en proveedor RENIEC', [
                'dni' => $dni,
                'url' => "{$url}/dni",
            ]);

            // Compatibilidad: algunos proveedores aceptan Bearer y otros query token.
            $attempts = [
                [
                    'auth' => 'bearer',
                    'response' => $baseHttp
                        ->withToken($token)
                        ->post("{$url}/dni", ['dni' => $dni]),
                ],
                [
                    'auth' => 'query',
                    'response' => $baseHttp
                        ->withQueryParameters(['token' => $token])
                        ->post("{$url}/dni", ['dni' => $dni]),
                ],
            ];

            foreach ($attempts as $attempt) {
                $response = $attempt['response'];

                Log::info('ReniecService: Respuesta recibida', [
                    'dni' => $dni,
                    'auth' => $attempt['auth'],
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                if ($response->status() === 401) {
                    continue;
                }

                if ($response->successful() && $response->json('success')) {
                    $data = $response->json('data') ?? [];

                    Log::info('ReniecService: DNI consultado exitosamente.', [
                        'dni' => $dni,
                        'nombres' => $data['nombres'] ?? 'N/A',
                    ]);

                    return [
                        'nombres' => $data['nombres'] ?? '',
                        'apellidos' => trim(
                            ($data['apellido_paterno'] ?? $data['apellidoPaterno'] ?? '') . ' ' .
                            ($data['apellido_materno'] ?? $data['apellidoMaterno'] ?? '')
                        ),
                    ];
                }

                $message = strtolower((string) ($response->json('message') ?? ''));
                if ($message !== '' && str_contains($message, 'no se encontraron resultados')) {
                    return [];
                }
            }

            Log::warning('ReniecService: token invalido o respuesta no compatible.', [
                'dni' => $dni,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('ReniecService: excepcion al consultar DNI.', [
                'dni' => $dni,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
