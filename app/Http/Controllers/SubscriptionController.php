<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Services\StripeService;
use App\Models\Plan;

class SubscriptionController extends Controller
{
    public function plans()
    {
        return response()->json([
            'plans' => Plan::featureMatrix(),
        ]);
    }

   public function createSubscriptionIntent(Request $request, StripeService $stripe)
{
    $validator = Validator::make($request->all(), [
        'cycle'    => 'required|in:monthly,annual',
        'currency' => 'required|in:usd,gbp',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();

    // ✅ JWT user
    $user = JWTAuth::parseToken()->authenticate();

    $payload = $stripe->createPremiumSubscriptionIntent(
        $user,
        $data['cycle'],
        $data['currency']
    );

    return response()->json($payload, 200);
}


    public function me(Request $request)
    {
        // ✅ JWT user (since you're not using $request->user())
        $user = JWTAuth::parseToken()->authenticate();

        $plan = $user->currentPlan();
        $sub  = $user->subscription;

        return response()->json([
            'plan'               => $plan->slug,
            'plan_name'          => $plan->name,
            'is_premium'         => $user->isPremium(),
            'current_period_end' => $sub ? $sub->current_period_end : null,
            'status'             => $sub ? $sub->status : null,
        ], 200);
    }
}
