<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InternalNotification;
use App\Models\SuspiciousSale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FraudDetectionService
{
    /* ─── Score thresholds ────────────────────────────────── */
    private const SCORE_DNI_DUPLICATE         = 50;
    private const SCORE_PHONE_DUPLICATE       = 20;
    private const SCORE_ADDRESS_SIMILAR       = 30;
    private const SCORE_EARLY_CANCELLATION    = 70;  // < 3 months
    private const SCORE_REHIRE_FAST           = 80;  // < 6 months

    /* ─── Risk level boundaries ──────────────────────────── */
    private const LEVEL_BAJO_MAX  = 30;
    private const LEVEL_MEDIO_MAX = 70;

    /**
     * Analyze a client for fraud indicators.
     * Returns the SuspiciousSale record if risk >= medio, null otherwise.
     */
    public function analyzeClient(Client $client): ?SuspiciousSale
    {
        $reasons = [];
        $score   = 0;

        // ── 1. DNI duplicado ────────────────────────────────
        $dniCount = Client::where('dni', $client->dni)
            ->where('id', '!=', $client->id)
            ->count();

        if ($dniCount > 0) {
            $score += self::SCORE_DNI_DUPLICATE;
            $reasons[] = [
                'rule'   => 'dni_duplicado',
                'label'  => "DNI {$client->dni} registrado en {$dniCount} cliente(s) más",
                'points' => self::SCORE_DNI_DUPLICATE,
            ];
        }

        // ── 2. Teléfono duplicado ───────────────────────────
        $phoneCount = Client::where('id', '!=', $client->id)
            ->where(function ($q) use ($client) {
                $q->where('telefono_1', $client->telefono_1);
                if ($client->telefono_2) {
                    $q->orWhere('telefono_1', $client->telefono_2)
                      ->orWhere('telefono_2', $client->telefono_1)
                      ->orWhere('telefono_2', $client->telefono_2);
                }
            })
            ->count();

        if ($phoneCount > 0) {
            $score += self::SCORE_PHONE_DUPLICATE;
            $reasons[] = [
                'rule'   => 'telefono_duplicado',
                'label'  => "Teléfono coincide con {$phoneCount} cliente(s)",
                'points' => self::SCORE_PHONE_DUPLICATE,
            ];
        }

        // ── 3. Dirección similar ────────────────────────────
        if ($client->direccion) {
            $addressCount = Client::where('id', '!=', $client->id)
                ->where('distrito', $client->distrito)
                ->where('direccion', 'ilike', '%' . $this->normalizeAddress($client->direccion) . '%')
                ->count();

            if ($addressCount > 0) {
                $score += self::SCORE_ADDRESS_SIMILAR;
                $reasons[] = [
                    'rule'   => 'direccion_similar',
                    'label'  => "Dirección similar encontrada en {$addressCount} cliente(s) del mismo distrito",
                    'points' => self::SCORE_ADDRESS_SIMILAR,
                ];
            }
        }

        // ── 4. Baja temprana (< 3 meses) ───────────────────
        if ($client->estado === 'baja' && $client->created_at) {
            $monthsActive = $client->created_at->diffInMonths(now());
            if ($monthsActive < 3) {
                $score += self::SCORE_EARLY_CANCELLATION;
                $reasons[] = [
                    'rule'   => 'baja_temprana',
                    'label'  => "Cliente dado de baja a los {$monthsActive} mes(es) (mínimo 6)",
                    'points' => self::SCORE_EARLY_CANCELLATION,
                ];
            }
        }

        // ── 5. Recontratación rápida (< 6 meses) ───────────
        $previousClient = Client::where('dni', $client->dni)
            ->where('id', '!=', $client->id)
            ->where('estado', 'baja')
            ->orderByDesc('updated_at')
            ->first();

        if ($previousClient) {
            $monthsSinceBaja = $previousClient->updated_at->diffInMonths($client->created_at);
            if ($monthsSinceBaja < 6) {
                $score += self::SCORE_REHIRE_FAST;
                $reasons[] = [
                    'rule'   => 'recontratacion_rapida',
                    'label'  => "Recontratación {$monthsSinceBaja} mes(es) después de baja anterior",
                    'points' => self::SCORE_REHIRE_FAST,
                ];
            }
        }

        // ── Classify risk level ─────────────────────────────
        $level = $this->classifyRisk($score);

        // ── Update client risk fields ───────────────────────
        $client->update([
            'risk_score'    => $score,
            'is_suspicious' => $level !== 'bajo',
        ]);

        // ── Only create record if risk >= medio ─────────────
        if ($level === 'bajo') {
            return null;
        }

        $suspicious = SuspiciousSale::updateOrCreate(
            [
                'client_id' => $client->id,
                'status'    => 'pendiente',
            ],
            [
                'user_id'    => $client->user_id,
                'risk_score' => $score,
                'risk_level' => $level,
                'reasons'    => $reasons,
            ]
        );

        // ── Notify admins ───────────────────────────────────
        $this->notifyAdmins($client, $suspicious);

        return $suspicious;
    }

    /**
     * Classify risk score into level.
     */
    public function classifyRisk(int $score): string
    {
        if ($score <= self::LEVEL_BAJO_MAX) {
            return 'bajo';
        }

        if ($score <= self::LEVEL_MEDIO_MAX) {
            return 'medio';
        }

        return 'alto';
    }

    /**
     * Normalize address for comparison (remove common words, lowercase).
     */
    private function normalizeAddress(string $address): string
    {
        $address = mb_strtolower(trim($address));
        $remove  = ['calle', 'av.', 'av', 'avenida', 'jr.', 'jr', 'jirón', 'pasaje', 'psje', 'mz', 'lt', 'mza', 'lote', 'nro', 'n°', '#', '.', ','];

        foreach ($remove as $word) {
            $address = str_replace($word, '', $address);
        }

        return preg_replace('/\s+/', ' ', trim($address));
    }

    /**
     * Notify all admin users about suspicious sale.
     */
    private function notifyAdmins(Client $client, SuspiciousSale $suspicious): void
    {
        $admins = User::where('role', 'admin')->where('active', true)->get();

        foreach ($admins as $admin) {
            InternalNotification::create([
                'user_id' => $admin->id,
                'tipo'    => 'alerta_fraude',
                'titulo'  => "Venta sospechosa detectada — Riesgo {$suspicious->risk_level}",
                'mensaje' => "Cliente {$client->nombres} {$client->apellidos} (DNI: {$client->dni}) tiene un score de riesgo de {$suspicious->risk_score}.",
                'data'    => [
                    'suspicious_sale_id' => $suspicious->id,
                    'client_id'          => $client->id,
                    'risk_level'         => $suspicious->risk_level,
                    'link'               => '/ventas-sospechosas',
                ],
            ]);
        }
    }
}
