<?php

namespace App\Http\Controllers;
use App\Models\User;


use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Mail\Sendotp;
use Illuminate\Support\Facades\Mail;
use JWTAuth;
use Config;
use Auth;
use Carbon\Carbon;
use Tymon\JWTAuth\Exceptions\JWTException;
class Authcontroller extends Controller
{
   

        public function register(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'     => 'required|string|max:150',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|max:30|unique:users,phone',
            'password' => 'required|string|min:8|confirmed' // expects password_confirmation
        ]);

        if ($v->fails()) {

            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Registration successful',
            'user'    => $user,
            'token'   => $token
        ], 201);
    }

    /** LOGIN (JWT) */
    public function login(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => auth()->user()
        ],200);
    }

    /** REQUEST FORGOT PASSWORD OTP
     * Returns an encrypted token (contains otp + expiry + email)
     */
    public function requestForgotPasswordOTP(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $otp = random_int(100000, 999999);
        $expiresAt = Carbon::now()->addMinutes(5)->timestamp;

        // Encrypt payload like your Node version
        $payload = json_encode([
            'otp'   => (string)$otp,
            'exp'   => $expiresAt,
            'email' => $user->email
        ]);
        $encryptedToken = Crypt::encryptString($payload);

        // Send email
        Mail::to($user->email)->send(new OtpMail($user->name, $otp));

        return response()->json([
            'message' => 'OTP sent successfully',
            'token'   => $encryptedToken
        ]);
    }

    /** VERIFY FORGOT PASSWORD OTP */
    public function verifyForgotPasswordOTP(Request $request)
    {
        $v = Validator::make($request->all(), [
            'enteredOtp' => 'required|string',
            'token'      => 'required|string'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        try {
            $decoded = json_decode(Crypt::decryptString($request->token), true);
            $otp      = $decoded['otp'] ?? null;
            $exp      = $decoded['exp'] ?? null;
            $email    = $decoded['email'] ?? null;

            if (! $otp || ! $exp) {
                return response()->json(['message' => 'Invalid or tampered token'], 400);
            }

            if (Carbon::now()->timestamp > intval($exp)) {
                return response()->json(['message' => 'OTP expired'], 400);
            }

            if ($request->enteredOtp !== $otp) {
                return response()->json(['message' => 'Invalid OTP'], 400);
            }
            User::where('email', $email)->update([
             "verified"=>1
            ]);
            return response()->json(['message' => 'OTP verified successfully']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid or tampered token'], 400);
        }
    }

    /** RESET FORGOTTEN PASSWORD */
    public function resetForgottenPassword(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email'       => 'required|email',
            'newPassword' => 'required|string|min:8'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json(['message' => 'Password reset successfully']);
    }

    public function getusersProfile(Request $request)
{
         $token = JWTAuth::parseToken()->getPayload()->toArray();
                   $email=$token['email'];
        $user = User::select('name','phone','email','verified')->where('email', $email)->first();

        return response()->json($user);
}


public function update_password_from_dashboard(Request $request){
               $validator = Validator::make($request->all(), [
                'password' => 'required|string|min:6|confirmed',
                'old_password'=>'required'
                
            ]);
            if($validator->fails()){
                    return response()->json($validator->errors(), 400);
            }else{
                 $token = JWTAuth::parseToken()->getPayload()->toArray();
                   $id=$token['id'];
                   
                 $user= User::where('id', $id)->first();
                $pass=$user->password;
                if(Hash::check($request->old_password, $pass)){
                    
                $id=$user->id;
           
            $admin = User::where('id',$id)->update([
                'password'=>Hash::make($request->password)
            ]);
            return response()->json(["status"=>"success"],200);
                }else{
                    return response()->json([
                        "satus"=> "failed",
                        "message"=>"Incorrect password"
                        ]);
                }
                
                }
}

}



  
