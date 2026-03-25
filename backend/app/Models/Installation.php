<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Installation extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'duracion',
        'estado',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
        ];
    }

    /* ─── Relationships ──────────────────────────────────── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vendedora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function supervision(): HasOne
    {
        return $this->hasOne(Supervision::class);
    }

    /* ─── Scopes ─────────────────────────────────────────── */

    public function scopeForUser($query, User $user)
    {
        if ($user->isVendedora()) {
            $query->where('user_id', $user->id);
        }
        return $query;
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('fecha', $date);
    }

    public function scopeEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Overlapping installations:
     * An overlap exists when the existing slot starts before the new one ends
     * AND the existing slot ends after the new one starts.
     */
    public function scopeConflicts($query, string $fecha, string $horaInicio, string $horaFin, ?int $excludeId = null)
    {
        $query->where('fecha', $fecha)
              ->where('hora_inicio', '<', $horaFin)
              ->where('hora_fin',    '>',  $horaInicio);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query;
    }

    /* ─── Helpers ─────────────────────────────────────────── */

    /**
     * Calculate hora_fin = hora_inicio + $duracion hours (default 1).
     */
    public static function calcularHoraFin(string $horaInicio, int $duracion = 1): string
    {
        return Carbon::createFromFormat('H:i', $horaInicio)
            ->addHours($duracion)
            ->format('H:i');
    }

    public function getFechaFormateadaAttribute(): string
    {
        return Carbon::parse($this->fecha)->format('d/m/Y');
    }
}
