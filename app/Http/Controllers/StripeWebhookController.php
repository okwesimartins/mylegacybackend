<?php

// app/Http/Controllers/StripeWebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Webhook;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\User;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        $event = Webhook::constructEvent(
            $payload,
            $sigHeader,
            config('services.stripe.webhook_secret')
        );

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $this->syncFromSession($session);
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $sub = $event->data->object;
                $this->syncFromSubscriptionObject($sub);
                break;
        }

        return response()->json(['received' => true]);
    }

    private function syncFromSession($session): void
    {
        $userId = (int)($session->metadata->user_id ?? 0);
        $stripeSubId = $session->subscription ?? null;
        $customerId = $session->customer ?? null;

        if (!$userId || !$stripeSubId) return;

        // fetch subscription from stripe
        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
        $stripeSub = $stripe->subscriptions->retrieve($stripeSubId, []);

        $this->upsertUserSubscription($userId, $customerId, $stripeSub);
    }

    private function syncFromSubscriptionObject($stripeSub): void
    {
        // Try to map via stripe_customer_id
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return;

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function upsertUserSubscription(int $userId, ?string $customerId, $stripeSub): void
    {
        $plan = Plan::premium();

        $priceId = $stripeSub->items->data[0]->price->id ?? null;
        $interval = $stripeSub->items->data[0]->price->recurring->interval ?? null;
        $currency = $stripeSub->currency ?? null;

        Subscription::updateOrCreate(
            ['user_id' => $userId],
            [
                'plan_id' => $plan->id,
                'stripe_customer_id' => $customerId,
                'stripe_subscription_id' => $stripeSub->id,
                'status' => $stripeSub->status,
                'cancel_at_period_end' => (bool)$stripeSub->cancel_at_period_end,
                'current_period_end' => isset($stripeSub->current_period_end) ? now()->setTimestamp($stripeSub->current_period_end) : null,
                'currency' => $currency,
                'interval' => $interval,
                'price_id' => $priceId,
                'metadata' => ['raw' => ['id' => $stripeSub->id]],
            ]
        );
    }
}
