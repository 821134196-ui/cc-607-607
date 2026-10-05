<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceLanguage extends Model
{
    protected $fillable = ['performance_id', 'language', 'capacity'];

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    /** 当前未完结预约数（active 待领取 + issued 已发放） */
    public function openReservations(): int
    {
        return Reservation::where('performance_id', $this->performance_id)
            ->where('language', $this->language)
            ->whereIn('status', [Reservation::STATUS_ACTIVE, Reservation::STATUS_ISSUED])
            ->count();
    }
}
