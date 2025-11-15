<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalNextOfKin extends Model {
    protected $table = 'journal_next_of_kin';
     public $timestamps = false;
    protected $fillable = [
        'user_id','name','email','phone','relationship_type_id','trigger_type_id',
        'personal_message','passkey_hash','delivered_at','status','invite_token',
    ];
    public function trigger() { return $this->belongsTo(TriggerType::class,'trigger_type_id'); }
    public function relationship() { return $this->belongsTo(RelationshipType::class,'relationship_type_id'); }
    public function journals() { return $this->belongsToMany(Journals::class,'journal_nok_assignments','journal_next_of_kin_id','journal_id'); }

    
}