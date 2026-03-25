<?php

namespace App\Services;

use App\Models\Installation;
use Carbon\Carbon;

class ScheduleService
{
    // Business hours
    private const DAY_START   = '08:00';
    private const DAY_END     = '18:00';
    private const LUNCH_START = '13:00';
    private const LUNCH_END   = '15:00';

    /**
     * Returns the list of occupied time slots for a given date.
     *
     * @return array<int, array{id: int, hora_inicio: string, hora_fin: string}>
     */
    public function getOccupiedSlots(string $date, ?int $excludeId = null): array
    {
        return Installation::where('fecha', $date)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->select('id', 'hora_inicio', 'hora_fin')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn ($i) => [
                'id'          => $i->id,
                'hora_inicio' => substr($i->hora_inicio, 0, 5),
                'hora_fin'    => substr($i->hora_fin,    0, 5),
            ])
            ->toArray();
    }

    /**
     * Returns available start hours with per-duration availability.
     * Hours: 08:00–17:00, excluding lunch start times (13:00, 14:00).
     * Durations: 1h, 2h.
     * Restrictions:
     *   - Range must not cross 13:00–15:00 lunch block
     *   - Range must not exceed 18:00
     *   - Range must not overlap existing installations
     *
     * @return array<int, array{hora_inicio: string, duraciones: array}>
     */
    public function getAvailableSlots(string $date, ?int $excludeId = null): array
    {
        $occupied = $this->getOccupiedSlots($date, $excludeId);
        $slots    = [];

        for ($h = 8; $h <= 17; $h++) {
            $start = sprintf('%02d:00', $h);

            // Skip lunch-block start hours
            if ($start >= self::LUNCH_START && $start < self::LUNCH_END) {
                continue;
            }

            $duraciones = [];
            foreach ([1, 2] as $dur) {
                $end = Carbon::createFromFormat('H:i', $start)->addHours($dur)->format('H:i');

                if ($end > self::DAY_END) {
                    $duraciones[$dur] = [
                        'disponible' => false,
                        'hora_fin'   => $end,
                        'motivo'     => 'fuera_de_horario',
                    ];
                    continue;
                }

                // Overlap check with lunch: [start, end) ∩ [LUNCH_START, LUNCH_END) ≠ ∅
                if ($start < self::LUNCH_END && $end > self::LUNCH_START) {
                    $duraciones[$dur] = [
                        'disponible' => false,
                        'hora_fin'   => $end,
                        'motivo'     => 'horario_almuerzo',
                    ];
                    continue;
                }

                $conflict = null;
                foreach ($occupied as $slot) {
                    if ($slot['hora_inicio'] < $end && $slot['hora_fin'] > $start) {
                        $conflict = "{$slot['hora_inicio']}–{$slot['hora_fin']}";
                        break;
                    }
                }

                $duraciones[$dur] = [
                    'disponible'    => $conflict === null,
                    'hora_fin'      => $end,
                    'conflicto_con' => $conflict,
                ];
            }

            $slots[] = [
                'hora_inicio' => $start,
                'duraciones'  => $duraciones,
            ];
        }

        return $slots;
    }

    /**
     * Legacy: returns fixed 3-hour blocks (kept for backward compatibility).
     *
     * @return array<int, array{hora: string, hora_fin: string, disponible: bool, conflicto_con: string|null}>
     */
    public function getAvailableHours(string $date, ?int $excludeId = null): array
    {
        $occupied = $this->getOccupiedSlots($date, $excludeId);
        $slots    = [];

        for ($h = 7; $h <= 16; $h++) {
            $start = sprintf('%02d:00', $h);
            $end   = sprintf('%02d:00', $h + 3);

            $conflict = null;
            foreach ($occupied as $slot) {
                if ($slot['hora_inicio'] < $end && $slot['hora_fin'] > $start) {
                    $conflict = "{$slot['hora_inicio']}–{$slot['hora_fin']}";
                    break;
                }
            }

            $slots[] = [
                'hora'          => $start,
                'hora_fin'      => $end,
                'disponible'    => $conflict === null,
                'conflicto_con' => $conflict,
            ];
        }

        return $slots;
    }

    /**
     * Check if a given time range conflicts with any existing installation.
     */
    public function hasConflict(string $date, string $start, string $end, ?int $excludeId = null): bool
    {
        return Installation::conflicts($date, $start, $end, $excludeId)->exists();
    }

    /**
     * Validate a proposed slot against business rules (no backend conflict check).
     * Returns null if valid, or a string describing the problem.
     */
    public function validateSlot(string $horaInicio, int $duracion): ?string
    {
        $end = Carbon::createFromFormat('H:i', $horaInicio)->addHours($duracion)->format('H:i');

        if ($horaInicio < self::DAY_START) {
            return 'El horario de inicio no puede ser antes de las ' . self::DAY_START . '.';
        }

        if ($end > self::DAY_END) {
            return 'El horario termina después de las ' . self::DAY_END . '.';
        }

        if ($horaInicio >= self::LUNCH_START && $horaInicio < self::LUNCH_END) {
            return 'No se pueden programar instalaciones durante el horario de almuerzo (' . self::LUNCH_START . '–' . self::LUNCH_END . ').';
        }

        if ($horaInicio < self::LUNCH_END && $end > self::LUNCH_START) {
            return 'El rango ' . $horaInicio . '–' . $end . ' cruza el horario de almuerzo (' . self::LUNCH_START . '–' . self::LUNCH_END . ').';
        }

        return null;
    }
}
