<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Journaltemplate extends Model
{
    protected $table = 'journal_template';
    public $timestamps = false;
    protected $fillable = [
       'template',
    ];
  
}