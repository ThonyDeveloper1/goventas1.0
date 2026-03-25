<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SuspiciousSale;
use App\Services\FraudDetectionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuspiciousSaleController extends Controller
{
    public function __construct(
        private FraudDetectionService $fraudService
    ) {}

    /* ─────────────────────────────────────────────────────────
     |  GET /suspicious-sales
     ────────────────────────────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $query = SuspiciousSale::with([
            'client:id,dni,nombres,apellidos,telefono_1,direccion,distrito,estado,is_suspicious,risk_score',
            'vendedora:id,name',
            'reviewer:id,name',
        ]);

        if ($status = $request->input('status')) {
            $query->status($status);
        }

        if ($level = $request->input('risk_level')) {
            $query->riskLevel($level);
        }

        if ($search = $request->input('search')) {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('dni', 'ilike', "%{$search}%");
            });

                if ($userId = $request->input('user_id')) {
                    $query->where('user_id', (int) $userId);
                }

                $allMonths = (bool) $request->input('all_months', false);
                if (! $allMonths) {
                    $month = $request->input('month', now()->format('Y-m'));
                    try {
                        [$y, $m] = explode('-', $month);
                        $start   = Carbon::create((int) $y, (int) $m, 1)->startOfMonth();
                        $end     = $start->copy()->endOfMonth();
                    } catch (\Exception $e) {
                        $start = now()->startOfMonth();
                        $end   = now()->endOfMonth();
                    }
                    $query->whereBetween('created_at', [$start, $end]);
                }
        }

        $sales = $query->orderByDesc('risk_score')
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return response()->json($sales);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /suspicious-sales/stats
     ────────────────────────────────────────────────────────── */
    public function stats(Request $request): JsonResponse
    {
        $allMonths = (bool) $request->input('all_months', false);
        $userId    = $request->input('user_id');
        $dateRange = null;

        if (! $allMonths) {
            $month = $request->input('month', now()->format('Y-m'));
            try {
                [$y, $m] = explode('-', $month);
                $start     = Carbon::create((int) $y, (int) $m, 1)->startOfMonth();
                $dateRange = [$start, $start->copy()->endOfMonth()];
            } catch (\Exception $e) {
                $dateRange = [now()->startOfMonth(), now()->endOfMonth()];
            }
        }

        $q = function () use ($userId, $dateRange) {
            $query = SuspiciousSale::query();
            if ($userId) {
                $query->where('user_id', (int) $userId);
            }
            if ($dateRange) {
                $query->whereBetween('created_at', $dateRange);
            }
            return $query;
        };

        $data = [
            'total'       => $q()->count(),
            'pendientes'  => $q()->status('pendiente')->count(),
            'aprobados'   => $q()->status('aprobado')->count(),
            'rechazados'  => $q()->status('rechazado')->count(),
            'alto'        => $q()->riskLevel('alto')->pendiente()->count(),
            'medio'       => $q()->riskLevel('medio')->pendiente()->count(),
        ];

        return response()->json($data);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /suspicious-sales/analyze/{client}
     ────────────────────────────────────────────────────────── */
    public function analyze(Client $client): JsonResponse
    {
        $result = $this->fraudService->analyzeClient($client);

        if (! $result) {
            return response()->json([
                'success'  => true,
                'message'  => 'Análisis completado. No se detectaron riesgos significativos.',
                'risk_level' => 'bajo',
                'risk_score' => $client->fresh()->risk_score,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Alerta: riesgo {$result->risk_level} detectado (score: {$result->risk_score}).",
            'data'    => $result->load(['client:id,dni,nombres,apellidos', 'vendedora:id,name']),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /suspicious-sales/{id}/approve
     ────────────────────────────────────────────────────────── */
    public function approve(Request $request, int $id): JsonResponse
    {
        $sale = SuspiciousSale::findOrFail($id);

        if ($sale->status !== 'pendiente') {
            return response()->json([
                'message' => 'Esta venta ya fue revisada.',
            ], 422);
        }

        $sale->update([
            'status'      => 'aprobado',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Remove suspicious flag from client
        $sale->client->update([
            'is_suspicious' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Venta aprobada. El cliente ya no está marcado como sospechoso.',
            'data'    => $sale->fresh()->load(['client:id,dni,nombres,apellidos', 'reviewer:id,name']),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /suspicious-sales/{id}/reject
     ────────────────────────────────────────────────────────── */
    public function reject(Request $request, int $id): JsonResponse
    {
        $sale = SuspiciousSale::findOrFail($id);

        if ($sale->status !== 'pendiente') {
            return response()->json([
                'message' => 'Esta venta ya fue revisada.',
            ], 422);
        }

        $sale->update([
            'status'      => 'rechazado',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Keep suspicious flag on client
        $sale->client->update([
            'is_suspicious' => true,
            'estado'        => 'suspendido',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Venta rechazada. El cliente permanece marcado como sospechoso.',

                /* ─────────────────────────────────────────────────────────
                 |  POST /suspicious-sales/{id}/unapprove
                 ────────────────────────────────────────────────────────── */
                public function unapprove(int $id): JsonResponse
                {
                    $sale = SuspiciousSale::findOrFail($id);

                    if ($sale->status === 'pendiente') {
                        return response()->json([
                            'message' => 'Esta venta ya está pendiente de revisión.',
                        ], 422);
                    }

                    $wasApproved = $sale->status === 'aprobado';

                    $sale->update([
                        'status'      => 'pendiente',
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                    ]);

                    // Restore suspicious flag if approval had cleared it
                    if ($wasApproved) {
                        $sale->client->update(['is_suspicious' => true]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Revisión anulada. La venta vuelve a estado pendiente.',
                        'data'    => $sale->fresh()->load(['client:id,dni,nombres,apellidos', 'reviewer:id,name']),
                    ]);
                }
            }
            'data'    => $sale->fresh()->load(['client:id,dni,nombres,apellidos', 'reviewer:id,name']),
        ]);
    }
}
