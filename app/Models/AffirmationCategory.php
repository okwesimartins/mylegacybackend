<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class AffirmationCategory extends Model
{
    protected $table = 'affirmations'; // you showed this table holds categories
    public $timestamps = false;
    protected $fillable = ['name','slug'];
}