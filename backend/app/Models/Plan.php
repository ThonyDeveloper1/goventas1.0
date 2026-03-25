<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'velocidad_bajada',
        'velocidad_subida',
        'precio',
        'condiciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'velocidad_bajada' => 'integer',
            'velocidad_subida' => 'integer',
            'precio'           => 'float',
            'activo'           => 'boolean',
        ];
    }

    /* ─── Accessors ─────────────────────────────────── */

    public function getVelocidadAttribute(): string
    {
        return "{$this->velocidad_bajada}/{$this->velocidad_subida} Mbps";
    }

    /* ─── Relationships ─────────────────────────────── */

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /* ─── Scopes ─────────────────────────────────────── */

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}
