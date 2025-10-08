<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Journals extends Model
{
    protected $table = 'journals';
    public $timestamps = false;
    protected $fillable = [
        'name','audio','text','date',
    ];
  
}