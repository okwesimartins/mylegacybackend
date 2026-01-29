<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;

class AttachUserPlan
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // If you have authenticate() working, this returns the Eloquent User model.
            $userModel = JWTAuth::parseToken()->authenticate();
        } catch (\Throwable $e) {
            $userModel = null;
        }

        // Fallback: if your setup only uses payload array
        if (!$userModel) {
            try {
                $payload = JWTAuth::parseToken()->getPayload()->toArray();
                $userId = $payload['id'] ?? $payload['sub'] ?? null;
                $userModel = $userId ? User::find($userId) : null;
            } catch (\Throwable $e) {
                $userModel = null;
            }
        }

        if ($userModel) {
            $plan = $userModel->currentPlan(); // from my previous response
            $request->attributes->set('auth_user', $userModel);
            $request->attributes->set('plan', $plan);
        }

        return $next($request);
    }
}
