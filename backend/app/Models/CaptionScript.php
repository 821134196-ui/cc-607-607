<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CaptionScript extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'performance_id',
        'language',
        'version_no',
        'title',
        'content',
        'file_path',
        'checksum',
        'status',
        'published_by',
        'published_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public static function checksumFor(string $content): string
    {
        return hash('sha256', $content);
    }

    public function storedFileStillIntact(): bool
    {
        if (! $this->file_path || ! Storage::disk('local')->exists($this->file_path)) {
            return false;
        }

        return hash('sha256', Storage::disk('local')->get($this->file_path)) === $this->checksum;
    }
}
