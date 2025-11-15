<?php
namespace App\Http\Controllers;
use App\Models\TriggerType;
use App\Models\RelationshipType;
use Illuminate\Http\Request;

class MetaDropdownController extends Controller
{

  public function listTriggers() {
        return response()->json(['status'=>200,'triggers'=>TriggerType::orderBy('name')->get()]);
    }

  public function listRelationships() {
        return response()->json(['status'=>200,'relationships'=>RelationshipType::orderBy('name')->get()]);
    }

}