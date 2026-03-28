<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Pago;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PagosService
{
    public function __construct(private readonly MikrotikIspService $ispService) {}

    /**
     * Registrar un pago para un cliente.
     * Si el cliente estaba moroso/suspendido → auto-reactivar en MikroTik.
     */
    public function registrarPago(array $data, int $registradoPorId): Pago
    {
        $client = Client::findOrFail($data['client_id']);

        return DB::transaction(function () use ($data, $client, $registradoPorId) {
            $pago = Pago::create([
                'client_id'     => $client->id,
                'monto'         => $data['monto'],
                'fecha_pago'    => $data['fecha_pago'] ?? now()->toDateString(),
                'metodo_pago'   => $data['metodo_pago'],
                'comprobante'   => $data['comprobante'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'user_id'       => $registradoPorId,
            ]);

            // Auto-reactivation on service layer without overriding manual estado.
            if (in_array($client->service_status, ['suspendido', 'cortado'], true)
                || in_array($client->estado, ['moroso', 'suspendido'], true)) {
                $this->reactivarCliente($client);
            }

            // Always recalculate fecha_vencimiento (+30 days from payment)
            $fechaPago = Carbon::parse($pago->fecha_pago);
            $client->updateQuietly([
                'fecha_vencimiento' => $fechaPago->addDays(30)->toDateString(),
            ]);

            return $pago->load(['client:id,nombres,apellidos,estado', 'registradoPor:id,name']);
        });
    }

    /**
     * Reactivar un cliente moroso/suspendido tras pago:
     * 1. Activar PPPoE en MikroTik
        * 2. Cambiar service_status a activo en BD
     */
    private function reactivarCliente(Client $client): void
    {
        // Activate on MikroTik if PPPoE user exists
        if (! empty($client->mikrotik_user) && ! empty($client->mikrotik_router_id)) {
            try {
                $this->ispService->activateUser($client->mikrotik_router_id, $client->mikrotik_user);
                Log::info("[Pagos] PPPoE reactivated for \"{$client->mikrotik_user}\" on router {$client->mikrotik_router_id}");
            } catch (\Throwable $e) {
                Log::error("[Pagos] Failed to reactivate PPPoE for \"{$client->mikrotik_user}\": {$e->getMessage()}");
            }
        }

        // Update only service layer status; keep manual commercial estado untouched.
        $client->updateQuietly([
            'service_status' => 'activo',
        ]);

        Log::info("[Pagos] Client #{$client->id} ({$client->nombre_completo}) reactivated after payment");
    }

    /**
     * Listar pagos paginados, opcionalmente filtrados por cliente.
     */
    public function findAll(?int $clientId = null, int $perPage = 15)
    {
        $query = Pago::with(['client:id,nombres,apellidos,dni', 'registradoPor:id,name'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('created_at');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        return $query->paginate($perPage);
    }

    /**
     * Obtener un pago por ID.
     */
    public function findOne(int $id): Pago
    {
        return Pago::with(['client:id,nombres,apellidos,dni,estado', 'registradoPor:id,name'])
            ->findOrFail($id);
    }
}
