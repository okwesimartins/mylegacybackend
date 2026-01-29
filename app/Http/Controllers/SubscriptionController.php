<?php
// app/Http/Controllers/SubscriptionController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    public function createCheckout(Request $request, StripeService $stripe)
    {
        $request->validate([
            'cycle' => 'required|in:monthly,annual',
            'currency' => 'required|in:usd,gbp',
            'success_url' => 'required|url',
            'cancel_url' => 'required|url',
        ]);

        $user = JWTAuth::parseToken()->authenticate();
        $session = $stripe->createPremiumCheckout(
            $user,
            $request->cycle,
            $request->currency,
            $request->success_url,
            $request->cancel_url
        );

        return response()->json($session);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $plan = $user->currentPlan();
        $sub = $user->subscription;

        return response()->json([
            'plan' => $plan->slug,
            'plan_name' => $plan->name,
            'is_premium' => $user->isPremium(),
            'current_period_end' => $sub?->current_period_end,
            'status' => $sub?->status,
        ]);
    }
}
