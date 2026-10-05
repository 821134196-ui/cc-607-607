<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningLanguagePool extends Model
{
    protected $fillable = ['screening_id', 'language', 'quota'];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }
}
