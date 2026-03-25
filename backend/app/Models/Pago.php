<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'monto',
        'fecha_pago',
        'metodo_pago',
        'comprobante',
        'observaciones',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'monto'      => 'float',
            'fecha_pago' => 'date',
        ];
    }

    /* ─── Relationships ─────────────────────────────── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
