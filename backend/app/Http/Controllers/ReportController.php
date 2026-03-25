<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private const COMMERCIAL_STATE_SQL = "CASE
        WHEN estado = 'baja' THEN 'baja'
        WHEN estado = 'suspendido' THEN 'suspendido'
        WHEN estado = 'pre_registro' THEN 'pre_registro'
        WHEN estado = 'finalizada' THEN 'finalizada'
        WHEN service_status = 'activo' THEN 'finalizada'
        WHEN estado = 'activo' THEN 'finalizada'
        ELSE 'pre_registro'
    END";

    /* ─────────────────────────────────────────────────────────
     |  GET /reports/sales
     |  Sales overview: totals, by period, by vendedora.
     ────────────────────────────────────────────────────────── */
    public function salesReport(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subMonths(6)->toDateString());
        $to   = $request->input('to', now()->toDateString());

        $cacheKey = "report:v2:sales:{$from}:{$to}";

        $data = Cache::remember($cacheKey, 300, function () use ($from, $to) {
            // ── Totals ─────────────────────────────────────────
            $totals = Client::select(
                    DB::raw('count(*) as total'),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'pre_registro') as pre_registro"),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'finalizada') as finalizadas"),
                    DB::raw("count(*) filter (where estado = 'suspendido') as suspendidos"),
                    DB::raw("count(*) filter (where estado = 'baja') as bajas"),
                )
                ->whereBetween('created_at', [$from, "{$to} 23:59:59"])
                ->first();

            // ── By month ───────────────────────────────────────
            $byMonth = Client::select(
                    DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                    DB::raw('count(*) as total'),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'pre_registro') as pre_registro"),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'finalizada') as finalizadas"),
                    DB::raw("count(*) filter (where estado = 'baja') as bajas"),
                )
                ->whereBetween('created_at', [$from, "{$to} 23:59:59"])
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            // ── By vendedora ───────────────────────────────────
            $byVendor = Client::select(
                    'user_id',
                    DB::raw('count(*) as total'),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'pre_registro') as pre_registro"),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'finalizada') as finalizadas"),
                    DB::raw("count(*) filter (where estado = 'baja') as bajas"),
                )
                ->whereBetween('created_at', [$from, "{$to} 23:59:59"])
                ->groupBy('user_id')
                ->with('vendedora:id,name')
                ->get()
                ->map(fn ($row) => [
                    'vendedora' => $row->vendedora?->name ?? 'Sin asignar',
                    'user_id'   => $row->user_id,
                    'total'     => $row->total,
                    'pre_registro' => $row->pre_registro,
                    'finalizadas'  => $row->finalizadas,
                    'bajas'     => $row->bajas,
                ]);

            return [
                'period'     => ['from' => $from, 'to' => $to],
                'totals'     => $totals,
                'by_month'   => $byMonth,
                'by_vendor'  => $byVendor,
            ];
        });

        return response()->json($data);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /reports/vendors
     |  Vendor performance: retention rate, activity.
     ────────────────────────────────────────────────────────── */
    public function vendorPerformance(Request $request): JsonResponse
    {
        $cacheKey = 'report:v2:vendors:' . md5($request->fullUrl());

        $data = Cache::remember($cacheKey, 300, function () {
            $vendors = User::where('role', 'vendedora')
                ->where('active', true)
                ->select('id', 'name')
                ->withCount([
                    'clients',
                    'clients as pre_registro_count' => fn ($q) => $q->where(function ($w) {
                        $w->where('estado', 'pre_registro')->orWhereNull('estado');
                    }),
                    'clients as finalizadas_count'  => fn ($q) => $q->where(function ($w) {
                        $w->whereIn('estado', ['activo', 'finalizada'])
                            ->orWhere('service_status', 'activo');
                    }),
                    'clients as suspendidos_count'  => fn ($q) => $q->where('estado', 'suspendido'),
                    'clients as bajas_count'        => fn ($q) => $q->where('estado', 'baja'),
                    'clients as suspicious_count'   => fn ($q) => $q->where('is_suspicious', true),
                ])
                ->get()
                ->map(function ($v) {
                    $total = $v->clients_count ?: 1;
                    $v->retention_rate = round((($total - $v->bajas_count) / $total) * 100, 1);
                    return $v;
                });

            // ── Top performers ─────────────────────────────────
            $topByClients   = $vendors->sortByDesc('clients_count')->values()->take(5);
            $topByRetention = $vendors->sortByDesc('retention_rate')->values()->take(5);

            return [
                'vendors'          => $vendors->sortByDesc('clients_count')->values(),
                'top_by_clients'   => $topByClients,
                'top_by_retention' => $topByRetention,
            ];
        });

        return response()->json($data);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /reports/clients
     |  Client list report with filters, optimized for export.
     ────────────────────────────────────────────────────────── */
    public function clientsByVendor(Request $request): JsonResponse
    {
        $query = Client::select(
                'id', 'dni', 'nombres', 'apellidos', 'telefono_1',
                'direccion', 'distrito', 'estado', 'service_status',
                'mikrotik_profile', 'user_id', 'is_suspicious',
                'risk_score', 'latitud', 'longitud', 'created_at'
            )
            ->with('vendedora:id,name');

        if ($vendorId = $request->input('vendor_id')) {
            $query->where('user_id', $vendorId);
        }

        if ($estado = $request->input('estado')) {
            if ($estado === 'finalizada') {
                $query->where(function ($q) {
                    $q->whereIn('estado', ['activo', 'finalizada'])
                        ->orWhere('service_status', 'activo');
                });
            } elseif ($estado === 'pre_registro') {
                $query->where(function ($q) {
                    $q->where('estado', 'pre_registro')
                        ->orWhereNull('estado');
                });
            } else {
                $query->where('estado', $estado);
            }
        }

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', "{$to} 23:59:59");
        }

        $clients = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        $clients->getCollection()->each(function ($c) {
            $c->append('nombre_completo');
            $c->setAttribute('estado_comercial', $this->resolveCommercialState($c));
        });

        return response()->json($clients);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /reports/map
     |  Clients with geo-coordinates for map rendering.
     ────────────────────────────────────────────────────────── */
    public function clientsMap(Request $request): JsonResponse
    {
        $query = Client::select(
                'id', 'dni', 'nombres', 'apellidos', 'telefono_1',
                'direccion', 'distrito', 'estado', 'latitud', 'longitud',
                'user_id'
            )
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->with('vendedora:id,name');

        if ($vendorId = $request->input('vendor_id')) {
            $query->where('user_id', $vendorId);
        }

        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }

        $clients = $query->get();
        $clients->each(fn ($c) => $c->append('nombre_completo'));

        return response()->json([
            'clients' => $clients,
            'count'   => $clients->count(),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /reports/summary
     |  Global system summary for dashboard.
     ────────────────────────────────────────────────────────── */
    public function summary(): JsonResponse
    {
        $data = Cache::remember('report:v2:summary', 300, function () {
            $clients = Client::select(
                    DB::raw('count(*) as total'),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'pre_registro') as pre_registro"),
                    DB::raw("count(*) filter (where " . self::COMMERCIAL_STATE_SQL . " = 'finalizada') as finalizadas"),
                    DB::raw("count(*) filter (where estado = 'suspendido') as suspendidos"),
                    DB::raw("count(*) filter (where estado = 'baja') as bajas"),
                    DB::raw("count(*) filter (where is_suspicious = true) as sospechosos"),
                )
                ->first();

            $installations = Installation::select(
                    DB::raw('count(*) as total'),
                    DB::raw("count(*) filter (where estado = 'pendiente') as pendientes"),
                    DB::raw("count(*) filter (where estado = 'completado') as completadas"),
                )
                ->first();

            $thisMonth = Client::where('created_at', '>=', now()->startOfMonth())
                ->count();

            $lastMonth = Client::whereBetween('created_at', [
                    now()->subMonth()->startOfMonth(),
                    now()->subMonth()->endOfMonth(),
                ])
                ->count();

            $growth = $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0);

            $vendorCount = User::where('role', 'vendedora')->where('active', true)->count();

            return [
                'clients'       => $clients,
                'installations' => $installations,
                'this_month'    => $thisMonth,
                'last_month'    => $lastMonth,
                'growth'        => $growth,
                'vendor_count'  => $vendorCount,
            ];
        });

        return response()->json($data);
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

        if ($client->estado === 'finalizada' || $client->estado === 'activo' || $client->service_status === 'activo') {
            return 'finalizada';
        }

        return 'pre_registro';
    }
}
