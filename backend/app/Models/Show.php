<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Show extends Model
{
    protected $fillable = ['title', 'description'];

    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class);
    }
}
