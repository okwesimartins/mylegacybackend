<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Journals extends Model
{
    protected $table = 'journals';
    public $timestamps = false;
    protected $fillable = [
       'user_id', 'name','template_id',
    ];

      public function entries()
    {
        return $this->hasMany(JournalEntry::class, 'journal_id');
    }
  
}