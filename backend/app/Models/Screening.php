<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screening extends Model
{
    protected $fillable = ['show_id', 'starts_at', 'ends_at', 'venue'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function languagePools(): HasMany
    {
        return $this->hasMany(ScreeningLanguagePool::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function subtitleVersions(): HasMany
    {
        return $this->hasMany(SubtitleVersion::class);
    }

    /** 演出是否已经开始（用于区分“开演后换稿”流程） */
    public function hasStarted(?\DateTimeInterface $now = null): bool
    {
        $now ??= now();
        return $this->starts_at->lessThanOrEqualTo($now);
    }

    public function hasEnded(?\DateTimeInterface $now = null): bool
    {
        $now ??= now();
        return $this->ends_at->lessThanOrEqualTo($now);
    }
}
