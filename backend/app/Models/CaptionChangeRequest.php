<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptionChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'performance_id',
        'language',
        'new_script_id',
        'current_script_id',
        'status',
        'reason',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'review_note',
        'reviewed_at',
        'switched_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'switched_at' => 'datetime',
        ];
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function newScript(): BelongsTo
    {
        return $this->belongsTo(CaptionScript::class, 'new_script_id');
    }

    public function currentScript(): BelongsTo
    {
        return $this->belongsTo(CaptionScript::class, 'current_script_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
