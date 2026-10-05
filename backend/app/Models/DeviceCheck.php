<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCheck extends Model
{
    protected $fillable = [
        'device_assignment_id', 'device_id', 'inspector_id', 'phase',
        'battery_percent', 'physical_ok', 'language_confirmed',
        'result', 'note', 'checked_at',
    ];

    protected $casts = [
        'battery_percent' => 'integer',
        'physical_ok' => 'boolean',
        'language_confirmed' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public const PHASE_ISSUE = 'issue';
    public const PHASE_RETURN = 'return';
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceAssignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
