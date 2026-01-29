<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;
use App\Models\JournalAttachment;
use App\Models\JournalNextOfKin;

class EnsureFeatureAccess
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        // Prefer middleware-attached user/plan (from AttachUserPlan)
        $user = $request->attributes->get('auth_user');
        $plan = $request->attributes->get('plan');

        // Fallback to JWT if not attached
        if (!$user || !$plan) {
            try {
                $user = JWTAuth::parseToken()->authenticate();
            } catch (\Throwable $e) {
                $user = null;
            }

            if (!$user) {
                try {
                    $payload = JWTAuth::parseToken()->getPayload()->toArray();
                    $userId = $payload['id'] ?? $payload['sub'] ?? null;
                    $user = $userId ? User::find($userId) : null;
                } catch (\Throwable $e) {
                    $user = null;
                }
            }

            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $plan = $user->currentPlan();
        }

        // Premium allows everything
        if ($plan->slug === 'premium') {
            return $next($request);
        }

        // Free plan enforcement
        switch ($ability) {

            // ✅ This is the important one for your /journals/entry route
            case 'journal_entry.save':
                // Only enforce if files are included
                if (!$request->hasFile('attachments')) {
                    return $next($request);
                }

                $incomingFiles = $request->file('attachments') ?? [];
                $incomingCount = is_array($incomingFiles) ? count($incomingFiles) : 1;

                $maxTotalUploads = (int)($plan->limit('attachments.max', 1)); // free = 1

                // Count existing attachments for this user (across all journals)
                $existingCount = JournalAttachment::whereHas('entry.journal', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->count();

                // Also block premium-only media types (video/audio)
                $files = is_array($incomingFiles) ? $incomingFiles : [$incomingFiles];
                foreach ($files as $file) {
                    if (!$file || !$file->isValid()) continue;
                    $mime = $file->getMimeType() ?? '';
                    if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
                        return response()->json([
                            'message' => 'Audio/Video uploads are Premium features.'
                        ], Response::HTTP_FORBIDDEN);
                    }
                }

                // Enforce total upload limit
                if (($existingCount + $incomingCount) > $maxTotalUploads) {
                    return response()->json([
                        'message' => 'Free plan allows only 1 total file upload. Upgrade to Premium for unlimited uploads.'
                    ], Response::HTTP_FORBIDDEN);
                }

                return $next($request);

            case 'next_of_kin.create':
                $max = (int)($plan->limit('next_of_kin.max', 1));
                $count = JournalNextOfKin::where('user_id', $user->id)->count();

                if ($count >= $max) {
                    return response()->json([
                        'message' => 'Free plan allows only 1 Next of Kin. Upgrade to Premium to add more.'
                    ], Response::HTTP_FORBIDDEN);
                }
                return $next($request);

            default:
                // Generic feature flag check
                if (!$plan->hasFeature($ability)) {
                    return response()->json([
                        'message' => 'This feature is available on Premium.'
                    ], Response::HTTP_FORBIDDEN);
                }
                return $next($request);
        }
    }
}
