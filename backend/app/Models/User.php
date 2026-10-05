<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_AUDIENCE = 'audience';
    public const ROLE_FRONT_DESK = 'front_desk';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_MANAGER = 'manager';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'api_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_FRONT_DESK, self::ROLE_TECHNICIAN, self::ROLE_MANAGER], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function regenerateToken(): string
    {
        $token = Str::random(60);
        $this->forceFill(['api_token' => $token])->save();

        return $token;
    }
}
