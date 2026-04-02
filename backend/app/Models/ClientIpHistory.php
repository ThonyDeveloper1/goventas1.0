<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientIpHistory extends Model
{
    protected $table = 'client_ip_history';

    protected $fillable = [
        'client_id',
        'previous_ip',
        'new_ip',
        'assigned_by',
        'notes',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
