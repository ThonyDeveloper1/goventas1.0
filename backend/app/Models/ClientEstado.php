<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientEstado extends Model
{
    protected $table = 'cliente_estados';

    protected $fillable = [
        'nombre',
        'color',
        'descripcion',
        'orden',
        'activo',
        'sistema_protegido',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'sistema_protegido' => 'boolean',
    ];

    /**
     * Relación: un estado tiene muchos clientes
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'cliente_estado_id');
    }

    /**
     * Scopes útiles
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeProtegidos($query)
    {
        return $query->where('sistema_protegido', true);
    }

    public function scopeNoProtegidos($query)
    {
        return $query->where('sistema_protegido', false);
    }

    /**
     * Validar si el estado está siendo usado
     */
    public function estaEnUso(): bool
    {
        return $this->clients()->count() > 0;
    }
}
