<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class UserAffirmationPref extends Model
{
    protected $table = 'user_affirmation_prefs';
    public $timestamps = false;
    protected $fillable = [
        'user_id','category_id','times_per_day','day_start','day_end','active'
    ];
}