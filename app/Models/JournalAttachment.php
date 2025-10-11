<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalAttachment extends Model
{
    protected $table = 'journal_attachments';
    public $timestamps = false;
    protected $fillable = [
        'entry_id','stored_name','original_name','mime','size',
    ];

     public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

}