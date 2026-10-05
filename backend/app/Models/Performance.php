<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Performance extends Model
{
    protected $fillable = ['title', 'venue', 'starts_at', 'ends_at', 'description'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function languages(): HasMany
    {
        return $this->hasMany(PerformanceLanguage::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(CaptionScript::class);
    }

    public function hasLanguage(string $language): bool
    {
        return $this->languages->contains('language', $language);
    }

    /** 演出是否已开演（开演后换稿必须走审批） */
    public function hasStarted(?Carbon $at = null): bool
    {
        return ($at ?? Carbon::now())->greaterThanOrEqualTo($this->starts_at);
    }

    public function isFinished(?Carbon $at = null): bool
    {
        return ($at ?? Carbon::now())->greaterThanOrEqualTo($this->ends_at);
    }
}
