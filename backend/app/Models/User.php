<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'dni',
        'password',
        'role',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'active'            => 'boolean',
        ];
    }

    /* ─── Role helpers ─────────────────────────────────────────── */

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isVendedora(): bool
    {
        return $this->role === 'vendedora';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * @param  string|string[]  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        return is_array($roles)
            ? in_array($this->role, $roles, true)
            : $this->role === $roles;
    }

    /* ─── Relationships ────────────────────────────────────────── */

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'user_id');
    }
}
