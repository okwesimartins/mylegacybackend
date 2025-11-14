<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TriggerType extends Model {
    protected $fillable = ['name','kind','inactivity_days'];
}
