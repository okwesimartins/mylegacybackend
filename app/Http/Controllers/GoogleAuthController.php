<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class GoogleAuthController extends Controller
{
    public function verifyIdToken(Request $request)
    {
        $v = Validator::make($request->all(), [
            'id_token' => 'required|string'
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $Googleclientid = '126000492493-g21qano34eccoq3gg95vka90jt84deb1.apps.googleusercontent.com';
        try {
            $client = new GoogleClient(['client_id' => $Googleclientid]);
            $payload = $client->verifyIdToken($request->id_token);
            if (!$payload) {
                return response()->json(['message' => 'Invalid Google ID token'], 401);
            }

            // Security checks
            if (($payload['aud'] ?? null) !== $Googleclientid) {
                return response()->json(['message' => 'Token audience mismatch'], 401);
            }
            $email   = $payload['email'] ?? null;
            $sub     = $payload['sub'] ?? null; // Google unique user id
            $name    = $payload['name'] ?? '';
            $avatar  = $payload['picture'] ?? null;
            $emailVerified = ($payload['email_verified'] ?? false) ? Carbon::now() : null;

            if (!$email || !$sub) {
                return response()->json(['message' => 'Missing email or sub in Google token'], 400);
            }

            // Find or create user
            $user = User::where('google_id', $sub)->orWhere('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name'              => $name ?: Str::before($email, '@'),
                    'email'             => $email,
                    'password'          => bcrypt(Str::random(32)), // random; not used for Google login
                    'google_id'         => $sub,
                    'email_verified_at' => $emailVerified,
                    'avatar'            => $avatar,
                    'provider'          => 'google',
                ]);
            } else {
                // Link Google ID if not set yet, update avatar/verified status
                $user->google_id = $user->google_id ?: $sub;
                if ($avatar) $user->avatar = $avatar;
                if ($emailVerified && !$user->email_verified_at) $user->email_verified_at = $emailVerified;
                $user->provider = $user->provider ?: 'google';
                $user->save();
            }

            // Issue your app’s JWT
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'message' => 'Google auth success',
                'token'   => $token,
                'user'    => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'avatar' => $user->avatar,
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Google auth failed', 'error' => $e->getMessage()], 401);
        }
    }
}
