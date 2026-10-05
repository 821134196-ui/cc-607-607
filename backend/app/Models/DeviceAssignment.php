<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceAssignment extends Model
{
    protected $fillable = [
        'reservation_id', 'device_id', 'issued_by', 'issued_at',
        'returned_by', 'returned_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(DeviceCheck::class);
    }

    public function issueCheck(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeviceCheck::class)
            ->where('phase', DeviceCheck::PHASE_ISSUE);
    }

    public function isActive(): bool
    {
        return $this->returned_at === null;
    }
}
