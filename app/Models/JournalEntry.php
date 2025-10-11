<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';
    public $timestamps = false;
    protected $fillable = [
        'journal_id','title','text','date',
    ];


   public function journal()
    {
        return $this->belongsTo(Journals::class, 'journal_id');
    }

    public function attachments()
    {
        return $this->hasMany(JournalAttachment::class, 'entry_id');
    }
}