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
    $user = JWTAuth::parseToken()->authenticate();

    $result = $stripe->createPremiumSubscriptionIntent($user, $data['cycle'], $data['currency']);

    return response()->json([
        'success' => true,
        'data' => $result
    ], 200);
}



  public function me(Request $request)
{
  $user = JWTAuth::parseToken()->authenticate();

  // ✅ Give trial once if missing (new + existing)
  $user->ensureTrialIfMissing(7);

  $plan = $user->currentPlan();     // now includes trial => premium
  $sub  = $user->subscription;

  $isOnTrial = $user->isOnTrial();
  $isPaidPremium = $user->isPremium();
  $accessTier = $isPaidPremium ? 'premium' : ($isOnTrial ? 'trial' : 'free');

  return response()->json([
    'plan'               => $plan->slug,        // will be premium during trial
    'plan_name'          => $plan->name,
    'is_premium'         => $isPaidPremium,     // paid only
    'is_on_trial'        => $isOnTrial,
    'trial_ends_at'      => $user->trial_ends_at,
    'access_tier'        => $accessTier,        // trial | premium | free
    'current_period_end' => $sub ? $sub->current_period_end : null,
    'status'             => $sub ? $sub->status : null,
  ], 200);
}
    
    
    
/**
 * Verify all prices in your configuration
 * GET /api/subscriptions/verify-prices
 */
public function verifyPrices(StripeService $stripe)
{
    try {
        $results = $stripe->verifyAllPrices();
        
        $summary = [
            'total_prices' => count($results),
            'recurring_count' => 0,
            'one_time_count' => 0,
            'will_create_payment_intent' => 0,
            'issues' => [],
        ];

        foreach ($results as $key => $price) {
            if (isset($price['error'])) {
                $summary['issues'][] = "{$key}: {$price['error']}";
                continue;
            }

            if ($price['is_recurring']) {
                $summary['recurring_count']++;
            } else {
                $summary['one_time_count']++;
            }

            if ($price['will_create_payment_intent'] ?? false) {
                $summary['will_create_payment_intent']++;
            } else {
                $summary['issues'][] = "{$key}: Will NOT create payment intent (check trial_period_days or unit_amount)";
            }
        }
        
        return response()->json([
            'success' => true,
            'prices' => $results,
            'summary' => $summary,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Verify a single price ID
 * GET /api/subscriptions/verify-price/{priceId}
 */
public function verifyPrice($priceId, StripeService $stripe)
{
    try {
        $result = $stripe->verifyPrice($priceId);
        
        return response()->json([
            'success' => true,
            'price' => $result,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}
    
}
