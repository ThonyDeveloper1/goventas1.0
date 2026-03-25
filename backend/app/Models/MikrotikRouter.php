<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MikrotikRouter extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'use_tls',
        'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port'      => 'integer',
            'use_tls'   => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = Crypt::encryptString($value);
    }

    public function getDecryptedPasswordAttribute(): string
    {
        return Crypt::decryptString($this->attributes['password']);
    }
}
