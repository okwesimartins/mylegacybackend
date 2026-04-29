<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaticAffirmation extends Model
{
    use HasFactory;

    protected $table = 'static_affirmations';

    protected $fillable = [
        'prompt_order',
        'theme',
        'affirmations_values',
        'due',
    ];

    protected $casts = [
        'due' => 'boolean',
    ];
}
