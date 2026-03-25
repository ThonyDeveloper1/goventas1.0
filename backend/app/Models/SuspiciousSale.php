<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspiciousSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'user_id',
        'risk_score',
        'risk_level',
        'reasons',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reasons'     => 'array',
            'risk_score'  => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /* ─── Relationships ─────────────────────────────────── */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vendedora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* ─── Scopes ─────────────────────────────────────────── */

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRiskLevel($query, string $level)
    {
        return $query->where('risk_level', $level);
    }

    public function scopePendiente($query)
    {
        return $query->where('status', 'pendiente');
    }
}
