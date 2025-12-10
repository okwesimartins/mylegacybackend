<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AppleAuthController extends Controller
{
      
    public function verifyappleuser(Request $request){
        $v = Validator::make($request->all(), [
            'email' => 'required|string',
            'name'  => 'required|string',
            'apple_id' => 'required|string'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        try{
        $user = User::where('apple_id', $request->apple_id)->orWhere('email', $request->email)->first();

        if(!$user){
              $user =  User::create([
                  "email" => $request->email,
                  "name" => $request->name,
                  "apple_id"=> $request->apple_id
                ]);
        }else{
            $user->apple_id = $user->apple_id ?: $request->apple_id;

            $user->save();
        }

         $token = JWTAuth::fromUser($user);
          
         $checkstatus = User::where('email', $user->email)->where('account_status',1)->first();

        if(!$checkstatus){
            return response()->json(['message' => 'User account disabled'], 400);
        }

         return response()->json([
                'message' => 'Apple auth success',
                'token'   => $token,
                'user'    => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                ]
            ]);

             } catch (\Throwable $e) {
            return response()->json(['message' => 'Google auth failed', 'error' => $e->getMessage()], 401);
        }
    }

}