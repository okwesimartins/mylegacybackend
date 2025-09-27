<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class AffirmationInstance extends Model
{
    protected $table = 'affirmation_instances';
    public $timestamps = false;
    protected $fillable = [
        'user_id','category_id','text','scheduled_at','sent_at','dispatch_status','meta'
    ];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
        'meta'         => 'array'
    ];
}