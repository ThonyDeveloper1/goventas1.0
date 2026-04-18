<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisionEstado extends Model
{
    protected $fillable = [
        'nombre',
        'color',
        'descripcion',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden'  => 'integer',
    ];

    public function supervisions(): HasMany
    {
        return $this->hasMany(Supervision::class, 'estado_id');
    }
}
