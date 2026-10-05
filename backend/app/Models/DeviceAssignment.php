<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssignment extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_FAULTY = 'faulty';
    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'device_id',
        'reservation_id',
        'performance_id',
        'language',
        'status',
        'battery_at_issue',
        'language_at_issue',
        'serial_confirmed',
        'issued_by',
        'issued_at',
        'battery_at_return',
        'check_result',
        'fault_notes',
        'returned_by',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
