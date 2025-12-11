<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\AppleSignInService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AppleAuthController extends Controller
{
      
   public function verifyAppleUser(Request $request, AppleSignInService $appleService)
{
    // Client should send id_token from Apple
    $v = Validator::make($request->all(), [
        'id_token' => 'required|string',
        // Optional extras – first sign-in only
        'name'     => 'nullable|string',
        'email'    => 'nullable|string|email',
    ]);

    if ($v->fails()) {
        return response()->json(['errors' => $v->errors()], 422);
    }

    try {
        // 1. Verify id_token with Apple
        $claims = $appleService->verifyIdentityToken($request->id_token);

        // 2. Extract data from verified token
        $appleId = $claims->sub ?? null;           // unique Apple user id
        $appleEmail = $claims->email ?? null;      // may be real or relay

        if (!$appleId) {
            return response()->json(['message' => 'Invalid Apple token (no sub)'], 401);
        }

        // If frontend also sent email/name (first login), prefer token email
        $providedEmail = $request->email ?: $appleEmail;
        $nameFromClient = $request->name; // Apple sends name only on first auth

        // 3. Find existing user
        $user = User::where('apple_id', $appleId)->first();

        if (!$user && $providedEmail) {
            // Try link to existing email-based user
            $user = User::where('email', $providedEmail)->first();
        }

        if (!$user) {
            // 4. Create new user
            $user = User::create([
                'name'     => $nameFromClient ?: 'Apple User',
                'email'    => $providedEmail,   // can be null or relay
                'apple_id' => $appleId,
                // if you allow passwordless social accounts, leave password null or random
            ]);
        } else {
            // 5. Ensure apple_id is saved
            if (!$user->apple_id) {
                $user->apple_id = $appleId;
            }

            // Optionally update email if empty and Apple gave you one
            if (!$user->email && $appleEmail) {
                $user->email = $appleEmail;
            }

            $user->save();
        }

        // 6. Check account status
        if ($user->account_status != 1) {
            return response()->json(['message' => 'User account disabled'], 400);
        }

        // 7. Issue your app’s JWT
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Apple auth success',
            'token'   => $token,
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Apple auth failed',
            'error'   => $e->getMessage(),
        ], 401);
    }
}

}