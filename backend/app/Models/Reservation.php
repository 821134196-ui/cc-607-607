<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $fillable = [
        'code', 'screening_id', 'audience_id', 'audience_name', 'audience_phone',
        'language', 'status', 'release_reason', 'parent_id', 'ticket_id',
    ];

    public const STATUS_BOOKED = 'booked';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    /** 占用设备配额的状态 */
    public const OCCUPYING_STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CHECKED_IN,
    ];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audience_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Reservation::class, 'parent_id');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class)
            ->whereNull('returned_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function occupiesQuota(): bool
    {
        return in_array($this->status, self::OCCUPYING_STATUSES, true);
    }
}
