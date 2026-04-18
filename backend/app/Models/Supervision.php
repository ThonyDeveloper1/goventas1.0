<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supervision extends Model
{
    use HasFactory;

    protected $fillable = [
        'installation_id',
        'supervisor_id',
        'estado',
        'estado_id',
        'comentario',
        'fachada_verificada',
        'conexiones_verificadas',
        'ubicacion_confirmada',
        'servicio_verificado',
        'nivel_senal',
        'notas_supervisor',
    ];

    protected $casts = [
        'fachada_verificada'    => 'boolean',
        'conexiones_verificadas' => 'boolean',
        'ubicacion_confirmada'  => 'boolean',
        'servicio_verificado'   => 'boolean',
    ];

    /* ─── Relationships ──────────────────────────────────── */

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SupervisionPhoto::class);
    }

    public function estadoSupervision(): BelongsTo
    {
        return $this->belongsTo(SupervisionEstado::class, 'estado_id');
    }

    /* ─── Scopes ─────────────────────────────────────────── */

    public function scopeForUser($query, User $user)
    {
        if ($user->isSupervisor()) {
            $query->where('supervisor_id', $user->id);
        }
        return $query;
    }

    public function scopeEstado($query, string|int $estado)
    {
        if (is_numeric($estado)) {
            return $query->where('estado_id', $estado);
        }
        return $query->where('estado', $estado);
    }

    /* ─── Helpers ────────────────────────────────────────── */

    public function hasPhotos(): bool
    {
        return $this->photos()->exists();
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->supervisor_id === $user->id;
    }
}
