<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'channel',
        'to_address',
        'subject',
        'body',
        'related_type',
        'related_id',
    ];
}
