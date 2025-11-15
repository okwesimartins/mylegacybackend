<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class RelationshipType extends Model {
    protected $table = 'relationship_type';
    protected $fillable = ['name'];
}