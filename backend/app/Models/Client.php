<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'dni',
        'nombres',
        'apellidos',
        'telefono_1',
        'telefono_2',
        'direccion',
        'referencia',
        'departamento',
        'provincia',
        'distrito',
        'latitud',
        'longitud',
        'estado',
        'fecha_vencimiento',
        'user_id',
        'mikrotik_user',
        'mikrotik_password',
        'mikrotik_profile',
        'ip_address',
        'ip_override',
        'service_status',
        'is_suspicious',
        'risk_score',
        'plan_id',
        'mikrotik_router_id',
    ];

    protected $hidden = [
        'mikrotik_password',
    ];

    protected function casts(): array
    {
        return [
            'latitud'            => 'float',
            'longitud'           => 'float',
            'is_suspicious'      => 'boolean',
            'ip_override'        => 'boolean',
            'risk_score'         => 'integer',
            'fecha_vencimiento'  => 'date',
        ];
    }

    /* ─── Accessors ─────────────────────────────────────── */

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->nombres} {$this->apellidos}";
    }

    /* ─── Relationships ─────────────────────────────────── */

    public function vendedora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ClientPhoto::class);
    }

    public function suspiciousSales(): HasMany
    {
        return $this->hasMany(SuspiciousSale::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function mikrotikRouter(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(\App\Models\Pago::class);
    }

    public function ipHistory(): HasMany
    {
        return $this->hasMany(ClientIpHistory::class)->latest();
    }

    public function latestInstallation(): HasOne
    {
        return $this->hasOne(Installation::class)
            ->latestOfMany()
            ->select([
                'installations.id',
                'installations.client_id',
                'installations.fecha',
                'installations.hora_inicio',
                'installations.hora_fin',
                'installations.duracion',
                'installations.estado',
            ]);
    }

    /* ─── Scopes ─────────────────────────────────────────── */

    public function scopeForUser($query, User $user)
    {
        if ($user->isVendedora()) {
            $query->where('user_id', $user->id);
        }
        return $query;
    }

    public function scopeEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('nombres',   'ilike', "%{$term}%")
              ->orWhere('apellidos', 'ilike', "%{$term}%")
              ->orWhere('dni',       'ilike', "%{$term}%")
              ->orWhere('telefono_1','ilike', "%{$term}%")
              ->orWhere('mikrotik_user', 'ilike', "%{$term}%");
        });
    }

    public function scopeServiceStatus($query, string $status)
    {
        return $query->where('service_status', $status);
    }
}
