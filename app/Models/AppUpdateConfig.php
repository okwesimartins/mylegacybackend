<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUpdateConfig extends Model
{
    protected $table = 'app_update_configs';

    protected $fillable = [
        'type',
        'current_version',
        'minimum_version',
        'force_update',
        'update_message',
        'store_url',
        'release_date',
    ];

    protected $casts = [
        'force_update' => 'boolean',
        'release_date' => 'date',
    ];
}
