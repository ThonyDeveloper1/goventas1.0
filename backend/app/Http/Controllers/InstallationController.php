<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstallationRequest;
use App\Http\Requests\UpdateInstallationRequest;
use App\Models\Installation;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InstallationController extends Controller
{
    public function __construct(private readonly ScheduleService $schedule) {}

    /* ─────────────────────────────────────────────────────────
     |  GET /installations
     |  Filters: fecha, estado, user_id, client_id
     ────────────────────────────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Installation::query()
            ->with([
                'client:id,nombres,apellidos,dni,distrito',
                'vendedora:id,name',
            ])
            ->forUser($user);

        if ($fecha = $request->input('fecha')) {
            $query->forDate($fecha);
        }

        if ($estado = $request->input('estado')) {
            $query->estado($estado);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($userId = $request->input('user_id') and $user->isAdmin()) {
            $query->where('user_id', $userId);
        }

        // Date range for calendar view
        if ($from = $request->input('from')) {
            $query->where('fecha', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('fecha', '<=', $to);
        }

        $installations = $query
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->paginate($request->input('per_page', 30));

        $installations->getCollection()->each(
            fn ($i) => $i->append('fecha_formateada')
        );

        return response()->json($installations);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /installations/availability?fecha=YYYY-MM-DD
     |  Legacy endpoint (kept for backward compatibility)
     ────────────────────────────────────────────────────────── */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
        ]);

        $excludeId = $request->input('exclude_id');

        return response()->json([
            'fecha'     => $request->fecha,
            'ocupados'  => $this->schedule->getOccupiedSlots($request->fecha, $excludeId),
            'horarios'  => $this->schedule->getAvailableHours($request->fecha, $excludeId),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /installations/available-slots?date=YYYY-MM-DD[&exclude_id=N]
     |  Returns per-hour, per-duration availability (1h/2h/3h).
     |  Respects working hours (08:00–18:00) and lunch block (13:00–15:00).
     ────────────────────────────────────────────────────────── */
    public function availableSlots(Request $request): JsonResponse
    {
        $request->validate([
            'date'       => ['required', 'date_format:Y-m-d'],
            'exclude_id' => ['nullable', 'integer'],
        ]);

        $excludeId = $request->input('exclude_id');

        return response()->json([
            'fecha'   => $request->date,
            'ocupados' => $this->schedule->getOccupiedSlots($request->date, $excludeId),
            'slots'   => $this->schedule->getAvailableSlots($request->date, $excludeId),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /installations
     ────────────────────────────────────────────────────────── */
    public function store(StoreInstallationRequest $request): JsonResponse
    {
        $data      = $request->validated();
        $horaInicio = $data['hora_inicio'];
        $duracion   = $data['duracion'];
        $horaFin    = Installation::calcularHoraFin($horaInicio, $duracion);

        // Business-rule validation (hours, lunch block)
        if ($error = $this->schedule->validateSlot($horaInicio, $duracion)) {
            throw ValidationException::withMessages(['hora_inicio' => [$error]]);
        }

        $this->assertNoConflict($data['fecha'], $horaInicio, $horaFin);

        $installation = Installation::create([
            ...$data,
            'hora_fin' => $horaFin,
            'user_id'  => $request->user()->isAdmin()
                ? ($data['user_id'] ?? $request->user()->id)
                : $request->user()->id,
            'estado'   => $data['estado'] ?? 'pendiente',
        ]);

        $installation->load(['client:id,nombres,apellidos,dni', 'vendedora:id,name']);
        $installation->append('fecha_formateada');

        return response()->json([
            'success' => true,
            'message' => 'Instalación agendada correctamente.',
            'data'    => $installation,
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /installations/{installation}
     ────────────────────────────────────────────────────────── */
    public function show(Request $request, Installation $installation): JsonResponse
    {
        $this->authorizeAccess($request, $installation);
        $installation->load(['client', 'vendedora:id,name']);
        $installation->append('fecha_formateada');

        return response()->json($installation);
    }

    /* ─────────────────────────────────────────────────────────
     |  PUT /installations/{installation}
     ────────────────────────────────────────────────────────── */
    public function update(UpdateInstallationRequest $request, Installation $installation): JsonResponse
    {
        $this->authorizeAccess($request, $installation);

        $data       = $request->validated();
        $fecha      = $data['fecha']       ?? $installation->fecha->format('Y-m-d');
        $horaInicio = $data['hora_inicio'] ?? substr($installation->hora_inicio, 0, 5);
        $duracion   = $data['duracion'] ?? null;
        // Infer duration from existing record if not provided
        if ($duracion === null) {
            $existingStart = Carbon::createFromFormat('H:i:s', $installation->hora_inicio);
            $existingEnd   = Carbon::createFromFormat('H:i:s', $installation->hora_fin);
            $duracion = (int) $existingStart->diffInHours($existingEnd);
        }
        $horaFin = Installation::calcularHoraFin($horaInicio, $duracion);

        // Validate conflict only when fecha or hora changed
        $changed = isset($data['fecha']) || isset($data['hora_inicio']) || isset($data['duracion']);
        if ($changed) {
            if ($error = $this->schedule->validateSlot($horaInicio, $duracion)) {
                throw ValidationException::withMessages(['hora_inicio' => [$error]]);
            }
            $this->assertNoConflict($fecha, $horaInicio, $horaFin, $installation->id);
        }

        $installation->update([
            ...$data,
            'hora_fin' => $horaFin,
        ]);

        $installation->load(['client:id,nombres,apellidos,dni', 'vendedora:id,name']);
        $installation->append('fecha_formateada');

        return response()->json([
            'success' => true,
            'message' => 'Instalación actualizada correctamente.',
            'data'    => $installation,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  DELETE /installations/{installation}
     ────────────────────────────────────────────────────────── */
    public function destroy(Request $request, Installation $installation): JsonResponse
    {
        $this->authorizeAccess($request, $installation);
        $installation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Instalación eliminada.',
        ]);
    }

    /* ─── Private helpers ───────────────────────────────────── */

    private function assertNoConflict(
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int   $excludeId = null
    ): void {
        if ($this->schedule->hasConflict($fecha, $horaInicio, $horaFin, $excludeId)) {
            $occupied = $this->schedule->getOccupiedSlots($fecha, $excludeId);
            $slots    = implode(', ', array_map(
                fn ($s) => "{$s['hora_inicio']}–{$s['hora_fin']}",
                $occupied
            ));

            throw ValidationException::withMessages([
                'hora_inicio' => [
                    "El horario {$horaInicio}–{$horaFin} se superpone con una instalación existente. "
                    . "Horarios ocupados: {$slots}.",
                ],
            ]);
        }
    }

    private function authorizeAccess(Request $request, Installation $installation): void
    {
        $user = $request->user();
        if ($user->isVendedora() && $installation->user_id !== $user->id) {
            abort(403, 'No tienes permiso para acceder a esta instalación.');
        }
    }
}
