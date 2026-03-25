<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Installation;
use App\Models\InternalNotification;
use App\Models\Supervision;
use App\Models\SuspiciousSale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Auth::user();
        $month = $request->input('month');
        $allMonths = $request->boolean('all_months');
        $selectedUserId = $request->input('user_id');

        return match ($user->role) {
            'admin'      => $this->adminDashboard($month, $allMonths, $selectedUserId),
            'vendedora'  => $this->vendedoraDashboard($user, $month, $allMonths),
            'supervisor' => $this->supervisorDashboard($user, $month, $allMonths),
            default      => response()->json(['message' => 'Rol no reconocido'], 403),
        };
    }

    private function adminDashboard(?string $month, bool $allMonths, $selectedUserId): JsonResponse
    {
        $selectedMonth = $this->normalizedMonth($month);
        $targetUserId = is_numeric($selectedUserId) ? (int) $selectedUserId : null;

        $clientsQuery = Client::query()
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId));
        $this->applyMonthFilter($clientsQuery, 'created_at', $selectedMonth, $allMonths);

        $clients = $clientsQuery->select(
            DB::raw('count(*) as total'),
            DB::raw("count(*) filter (where estado = 'activo') as activos"),
            DB::raw("count(*) filter (where estado = 'moroso') as morosos"),
            DB::raw("count(*) filter (where estado = 'suspendido') as suspendidos"),
            DB::raw("count(*) filter (where estado = 'baja') as bajas"),
            DB::raw("count(*) filter (where is_suspicious = true) as sospechosos"),
        )->first();

        $installationsQuery = Installation::query()
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId));
        $this->applyMonthFilter($installationsQuery, 'fecha', $selectedMonth, $allMonths);

        $installations = $installationsQuery->select(
            DB::raw('count(*) as total'),
            DB::raw("count(*) filter (where estado = 'pendiente') as pendientes"),
            DB::raw("count(*) filter (where estado = 'en_proceso') as en_proceso"),
            DB::raw("count(*) filter (where estado = 'completado') as completadas"),
        )->first();

        $today = now()->toDateString();
        $installationsToday = Installation::query()
            ->where('fecha', $today)
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId))
            ->count();

        $monthBase = Carbon::createFromFormat('Y-m', $selectedMonth ?? now()->format('Y-m'));
        $thisMonthStart = $monthBase->copy()->startOfMonth();
        $thisMonthEnd = $monthBase->copy()->endOfMonth();
        $lastMonthStart = $monthBase->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $monthBase->copy()->subMonth()->endOfMonth();

        $thisMonth = Client::query()
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId))
            ->whereBetween('created_at', [$thisMonthStart, $thisMonthEnd])
            ->count();
        $lastMonth = Client::query()
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId))
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->count();
        $growth = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : ($thisMonth > 0 ? 100 : 0);

        $vendorCount = User::where('role', 'vendedora')->where('active', true)->count();
        $supervisorCount = User::where('role', 'supervisor')->where('active', true)->count();

        $suspiciousPending = SuspiciousSale::where('status', 'pendiente')->count();

        $recentClients = Client::with('vendedora:id,name')
            ->select('id', 'nombres', 'apellidos', 'dni', 'estado', 'user_id', 'created_at')
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId))
            ->when(! $allMonths, fn ($q) => $this->applyMonthFilter($q, 'created_at', $selectedMonth, false))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id'        => $c->id,
                'nombre'    => $c->nombres . ' ' . $c->apellidos,
                'dni'       => $c->dni,
                'estado'    => $c->estado,
                'vendedora' => $c->vendedora?->name,
                'fecha'     => $c->created_at->format('d/m/Y H:i'),
            ]);

        $recentInstallations = Installation::with(['client:id,nombres,apellidos', 'vendedora:id,name'])
            ->where('estado', 'pendiente')
            ->when($targetUserId, fn ($q) => $q->where('user_id', $targetUserId))
            ->when(! $allMonths, fn ($q) => $this->applyMonthFilter($q, 'fecha', $selectedMonth, false))
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'id'       => $i->id,
                'cliente'  => $i->client ? $i->client->nombres . ' ' . $i->client->apellidos : 'N/A',
                'fecha'    => $i->fecha,
                'hora'     => $i->hora_inicio . ' - ' . $i->hora_fin,
                'vendedora'=> $i->vendedora?->name,
            ]);

        return response()->json([
            'role' => 'admin',
            'clients' => $clients,
            'installations' => $installations,
            'installations_today' => $installationsToday,
            'this_month' => $thisMonth,
            'growth' => $growth,
            'vendor_count' => $vendorCount,
            'supervisor_count' => $supervisorCount,
            'suspicious_pending' => $suspiciousPending,
            'applied_month' => $selectedMonth,
            'all_months' => $allMonths,
            'selected_user_id' => $targetUserId,
            'recent_clients' => $recentClients,
            'recent_installations' => $recentInstallations,
        ]);
    }

    private function vendedoraDashboard(User $user, ?string $month, bool $allMonths): JsonResponse
    {
        $selectedMonth = $this->normalizedMonth($month);

        $clientsQuery = Client::where('user_id', $user->id);
        $this->applyMonthFilter($clientsQuery, 'created_at', $selectedMonth, $allMonths);

        $clients = $clientsQuery
            ->select(
                DB::raw('count(*) as total'),
                DB::raw("count(*) filter (where estado = 'activo') as activos"),
                DB::raw("count(*) filter (where estado = 'moroso') as morosos"),
                DB::raw("count(*) filter (where estado = 'suspendido') as suspendidos"),
                DB::raw("count(*) filter (where estado = 'baja') as bajas"),
            )->first();

        $monthBase = Carbon::createFromFormat('Y-m', $selectedMonth ?? now()->format('Y-m'));
        $thisMonth = Client::where('user_id', $user->id)
            ->whereBetween('created_at', [$monthBase->copy()->startOfMonth(), $monthBase->copy()->endOfMonth()])
            ->count();

        $pendingInstallationsQuery = Installation::where('user_id', $user->id)
            ->where('estado', 'pendiente');
        $this->applyMonthFilter($pendingInstallationsQuery, 'fecha', $selectedMonth, $allMonths);
        $pendingInstallations = $pendingInstallationsQuery->count();

        $recentClients = Client::where('user_id', $user->id)
            ->select('id', 'nombres', 'apellidos', 'dni', 'estado', 'created_at')
            ->when(! $allMonths, fn ($q) => $this->applyMonthFilter($q, 'created_at', $selectedMonth, false))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id'     => $c->id,
                'nombre' => $c->nombres . ' ' . $c->apellidos,
                'dni'    => $c->dni,
                'estado' => $c->estado,
                'fecha'  => $c->created_at->format('d/m/Y H:i'),
            ]);

        $myInstallations = Installation::with('client:id,nombres,apellidos')
            ->where('user_id', $user->id)
            ->where('estado', 'pendiente')
            ->when(! $allMonths, fn ($q) => $this->applyMonthFilter($q, 'fecha', $selectedMonth, false))
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'id'      => $i->id,
                'cliente' => $i->client ? $i->client->nombres . ' ' . $i->client->apellidos : 'N/A',
                'fecha'   => $i->fecha,
                'hora'    => $i->hora_inicio . ' - ' . $i->hora_fin,
            ]);

        return response()->json([
            'role' => 'vendedora',
            'clients' => $clients,
            'this_month' => $thisMonth,
            'applied_month' => $selectedMonth,
            'all_months' => $allMonths,
            'pending_installations' => $pendingInstallations,
            'recent_clients' => $recentClients,
            'my_installations' => $myInstallations,
        ]);
    }

    private function supervisorDashboard(User $user, ?string $month, bool $allMonths): JsonResponse
    {
        $selectedMonth = $this->normalizedMonth($month);
        $today = now()->toDateString();

        $supervisionsQuery = Supervision::where('supervisor_id', $user->id);
        $this->applyMonthFilter($supervisionsQuery, 'created_at', $selectedMonth, $allMonths);

        $supervisions = $supervisionsQuery
            ->select(
                DB::raw('count(*) as total'),
                DB::raw("count(*) filter (where estado = 'pendiente') as pendientes"),
                DB::raw("count(*) filter (where estado = 'en_proceso') as en_proceso"),
                DB::raw("count(*) filter (where estado = 'completado') as completadas"),
            )->first();

        $todayInstallations = Installation::whereHas('supervision', function ($q) use ($user) {
            $q->where('supervisor_id', $user->id);
        })->where('fecha', $today)->count();

        $pendingSupervisions = Supervision::with([
            'installation:id,client_id,fecha,hora_inicio,hora_fin',
            'installation.client:id,nombres,apellidos,direccion',
        ])
            ->where('supervisor_id', $user->id)
            ->whereIn('estado', ['pendiente', 'en_proceso'])
            ->when(! $allMonths, fn ($q) => $this->applyMonthFilter($q, 'created_at', $selectedMonth, false))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'estado'   => $s->estado,
                'cliente'  => $s->installation?->client
                    ? $s->installation->client->nombres . ' ' . $s->installation->client->apellidos
                    : 'N/A',
                'direccion'=> $s->installation?->client?->direccion,
                'fecha'    => $s->installation?->fecha,
                'hora'     => ($s->installation?->hora_inicio ?? '') . ' - ' . ($s->installation?->hora_fin ?? ''),
            ]);

        return response()->json([
            'role' => 'supervisor',
            'supervisions' => $supervisions,
            'today_installations' => $todayInstallations,
            'applied_month' => $selectedMonth,
            'all_months' => $allMonths,
            'pending_supervisions' => $pendingSupervisions,
        ]);
    }

    private function normalizedMonth(?string $month): string
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }

        return now()->format('Y-m');
    }

    private function applyMonthFilter($query, string $column, ?string $month, bool $allMonths): void
    {
        if ($allMonths) {
            return;
        }

        $base = Carbon::createFromFormat('Y-m', $month ?? now()->format('Y-m'));
        $query->whereBetween($column, [$base->copy()->startOfMonth(), $base->copy()->endOfMonth()]);
    }
}
