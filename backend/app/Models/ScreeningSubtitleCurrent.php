<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningSubtitleCurrent extends Model
{
    protected $table = 'screening_subtitle_current';

    protected $fillable = ['screening_id', 'language', 'subtitle_version_id'];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubtitleVersion::class, 'subtitle_version_id');
    }
}
