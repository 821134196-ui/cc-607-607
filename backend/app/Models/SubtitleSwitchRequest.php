<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleSwitchRequest extends Model
{
    protected $fillable = [
        'screening_id', 'language', 'from_version_id', 'to_version_id',
        'requested_by', 'decided_by', 'reason', 'status',
        'switched_at', 'decision_note',
    ];

    protected $casts = [
        'switched_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(SubtitleVersion::class, 'from_version_id');
    }

    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(SubtitleVersion::class, 'to_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
