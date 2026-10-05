<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'serial', 'label', 'supported_languages', 'status',
        'battery_percent', 'fault_note',
    ];

    protected $casts = [
        'supported_languages' => 'array',
        'battery_percent' => 'integer',
    ];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_FAULTY = 'faulty';

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    /** 当前未归还的发放记录（用于后台设备占用展示） */
    public function activeAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeviceAssignment::class)->whereNull('returned_at');
    }

    public function supportsLanguage(string $language): bool
    {
        return in_array($language, $this->supported_languages ?? [], true);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
