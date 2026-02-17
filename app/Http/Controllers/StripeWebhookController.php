<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;

use App\Models\Subscription;
use App\Models\Plan;
use App\Models\User;
use App\Models\StripeWebhookEvent;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('services.stripe.webhook_secret')
            );
        } catch (SignatureVerificationException $e) {
            Log::warning('[StripeWebhook] Invalid signature', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            Log::warning('[StripeWebhook] Invalid payload', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // ---- Idempotency ----
        $eventId = $event->id ?? null;
        if ($eventId) {
            $exists = StripeWebhookEvent::where('event_id', $eventId)->exists();
            if ($exists) {
                return response()->json(['received' => true, 'deduped' => true]);
            }

            StripeWebhookEvent::create([
                'event_id' => $eventId,
                'type' => $event->type ?? null,
                'processed_at' => now(),
            ]);
        }

        Log::info('[StripeWebhook] Received', [
            'type' => $event->type,
            'id'   => $eventId,
        ]);

        try {
            switch ($event->type) {

                // Subscription truth events (best source of status/period_end/price)
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->syncFromSubscriptionObject($event->data->object);
                    break;

                // Billing truth events (paid/failed) - we avoid calling Stripe unless needed
                case 'invoice.paid':
                case 'invoice.payment_failed':
                    $this->syncFromInvoiceObject($event->data->object);
                    break;

                // Optional / if you ever use Checkout again
                case 'checkout.session.completed':
                    $this->syncFromCheckoutSession($event->data->object);
                    break;

                default:
                    // ignore
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] Handler error', [
                'type' => $event->type,
                'id'   => $eventId,
                'err'  => $e->getMessage(),
                'trace'=> substr($e->getTraceAsString(), 0, 1200),
            ]);
            return response()->json(['error' => 'Webhook handler failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    private function syncFromCheckoutSession($session): void
    {
        $stripeSubId = $session->subscription ?? null;
        $customerId  = $session->customer ?? null;
        if (!$stripeSubId || !$customerId) return;

        $userId = (int)($session->metadata->user_id ?? 0);
        $user = $userId ? User::find($userId) : User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return;

        if (!$user->stripe_customer_id && $customerId) {
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSubId, [
            'expand' => ['items.data.price'],
        ]);

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function syncFromSubscriptionObject($stripeSub): void
    {
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return;

        $user = User::where('stripe_customer_id', $customerId)->first();

        if (!$user) {
            $metaUserId = (int)($stripeSub->metadata->user_id ?? 0);
            if ($metaUserId) $user = User::find($metaUserId);
        }

        if (!$user) return;

        if (!$user->stripe_customer_id) {
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        // Ensure price details exist (sometimes webhook includes full price already, sometimes not)
        // We will upsert using what we have; if price fields are missing, we do a minimal retrieve once.
        $needRetrieve = empty($stripeSub->items->data[0]->price);
        if ($needRetrieve) {
            $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSub->id, [
                'expand' => ['items.data.price'],
            ]);
        }

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function syncFromInvoiceObject($invoice): void
    {
        $customerId  = $invoice->customer ?? null;
        $stripeSubId = $invoice->subscription ?? null;
        if (!$customerId || !$stripeSubId) return;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return;

        // Fast path: update local status based on invoice outcome without fetching Stripe subscription
        $local = Subscription::where('user_id', $user->id)->first();

        if ($local) {
            if (($invoice->paid ?? false) === true) {
                // Keep current status if Stripe will send subscription.updated anyway,
                // but safe to mark active if it was incomplete/past_due.
                if (in_array($local->status, ['incomplete', 'past_due', 'unpaid'], true)) {
                    $local->status = 'active';
                    $local->save();
                }
            } else {
                // payment_failed
                // Stripe will also emit customer.subscription.updated -> past_due usually,
                // but we can reflect quickly.
                $local->status = $local->status === 'canceled' ? $local->status : 'past_due';
                $local->save();
            }

            Log::info('[StripeWebhook] Invoice synced (fast)', [
                'userId' => $user->id,
                'invoiceId' => $invoice->id ?? null,
                'paid' => (bool)($invoice->paid ?? false),
                'subscriptionId' => $stripeSubId,
            ]);

            return;
        }

        // If no local subscription record yet, fetch once and upsert.
        $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSubId, [
            'expand' => ['items.data.price'],
        ]);

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function upsertUserSubscription(int $userId, ?string $customerId, $stripeSub): void
    {
        $item     = $stripeSub->items->data[0] ?? null;
        $priceObj = $item->price ?? null;

        $priceId  = $priceObj->id ?? null;
        $interval = $priceObj->recurring->interval ?? null;
        $currency = $stripeSub->currency ?? ($priceObj->currency ?? null);

        // Resolve plan from price_id (works with your JSON column)
        $plan = $priceId ? Plan::findByStripePriceId($priceId) : null;
        if (!$plan) {
            $plan = method_exists(Plan::class, 'premium') ? Plan::premium() : Plan::first();
        }
        if (!$plan) {
            Log::warning('[StripeWebhook] No plan resolved', ['priceId' => $priceId, 'userId' => $userId]);
            return;
        }

        $currentPeriodEnd = isset($stripeSub->current_period_end)
            ? now()->setTimestamp((int)$stripeSub->current_period_end)
            : null;

        Subscription::updateOrCreate(
            ['user_id' => $userId],
            [
                'plan_id'                => $plan->id,
                'stripe_customer_id'     => $customerId,
                'stripe_subscription_id' => $stripeSub->id,
                'status'                 => $stripeSub->status,
                'cancel_at_period_end'   => (bool)($stripeSub->cancel_at_period_end ?? false),
                'current_period_end'     => $currentPeriodEnd,
                'currency'               => $currency,
                'interval'               => $interval,
                'price_id'               => $priceId,
                'metadata'               => [
                    'raw' => [
                        'id' => $stripeSub->id,
                        'status' => $stripeSub->status ?? null,
                        'collection_method' => $stripeSub->collection_method ?? null,
                    ],
                ],
            ]
        );

        Log::info('[StripeWebhook] Subscription synced', [
            'userId' => $userId,
            'stripeSubId' => $stripeSub->id ?? null,
            'status' => $stripeSub->status ?? null,
            'priceId' => $priceId,
            'interval' => $interval,
            'currency' => $currency,
        ]);
    }
}
