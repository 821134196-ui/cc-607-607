<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleVersion extends Model
{
    protected $fillable = [
        'screening_id', 'language', 'version_no', 'title',
        'file_path', 'file_size', 'file_hash',
        'published_by', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'file_size' => 'integer',
        'version_no' => 'integer',
    ];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function getContentAttribute(): string
    {
        return \Illuminate\Support\Facades\Storage::get($this->file_path);
    }
}
