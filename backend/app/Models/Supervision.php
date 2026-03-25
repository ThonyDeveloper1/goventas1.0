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
        'comentario',
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

    /* ─── Scopes ─────────────────────────────────────────── */

    public function scopeForUser($query, User $user)
    {
        if ($user->isSupervisor()) {
            $query->where('supervisor_id', $user->id);
        }
        return $query;
    }

    public function scopeEstado($query, string $estado)
    {
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
