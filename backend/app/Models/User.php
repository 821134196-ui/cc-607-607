<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = ['name', 'role', 'phone', 'email'];

    public const ROLE_AUDIENCE = 'audience';
    public const ROLE_FRONTDESK = 'frontdesk';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_MANAGER = 'manager';

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'audience_id');
    }
}
