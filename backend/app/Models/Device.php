<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'serial',
        'model',
        'status',
        'battery_level',
        'last_check_result',
        'fault_notes',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function isAllocatable(): bool
    {
        // 未归还(assigned)或故障维修中的设备绝不能分配给下一场
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function markFaulty(?string $notes, int $battery): void
    {
        $this->update([
            'status' => self::STATUS_MAINTENANCE,
            'last_check_result' => 'fault',
            'fault_notes' => $notes,
            'battery_level' => $battery,
            'checked_at' => now(),
        ]);
    }

    public function markHealthy(int $battery): void
    {
        $this->update([
            'status' => self::STATUS_AVAILABLE,
            'last_check_result' => 'ok',
            'fault_notes' => null,
            'battery_level' => $battery,
            'checked_at' => now(),
        ]);
    }
}
