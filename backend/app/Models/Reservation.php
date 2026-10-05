<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    public const STATUS_ACTIVE = 'active';     // 已预约待领取
    public const STATUS_ISSUED = 'issued';     // 已发放设备
    public const STATUS_RETURNED = 'returned'; // 已归还
    public const STATUS_CANCELLED = 'cancelled'; // 已取消 / 改签释放
    public const STATUS_NOSHOW = 'noshow';     // 未归还

    public const OPEN_STATUSES = [self::STATUS_ACTIVE, self::STATUS_ISSUED];

    protected $fillable = [
        'user_id',
        'performance_id',
        'language',
        'status',
        'contact_name',
        'contact_phone',
        'seq',
        'cancel_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
