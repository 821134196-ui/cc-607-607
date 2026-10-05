<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = ['code', 'screening_id', 'seat', 'status'];

    public const STATUS_VALID = 'valid';
    public const STATUS_USED = 'used';
    public const STATUS_REFUNDED = 'refunded';

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }
}
