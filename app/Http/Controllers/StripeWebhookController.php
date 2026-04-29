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
        $webhookSecret = config('services.stripe.webhook_secret', 'whsec_nAlWJeuHURjWDG9VVB3Bqq1P4MCiWAWX');

        Log::info('[StripeWebhook] Handle start', [
            'has_payload' => !empty($payload),
            'payload_bytes' => strlen($payload),
            'has_signature_header' => !empty($sigHeader),
            'request_ip' => $request->ip(),
        ]);

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::warning('[StripeWebhook] Invalid signature', [
                'err' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            Log::warning('[StripeWebhook] Invalid payload', [
                'err' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $eventId = $event->id ?? null;
        $eventType = $event->type ?? null;

        Log::info('[StripeWebhook] Event constructed', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'object_type' => $event->data->object->object ?? null,
            'livemode' => $event->livemode ?? null,
        ]);

        $webhookEvent = null;
        if ($eventId) {
            $webhookEvent = StripeWebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'type' => $eventType,
                    'processed_at' => null,
                ]
            );

            Log::info('[StripeWebhook] Idempotency record loaded', [
                'event_id' => $eventId,
                'db_id' => $webhookEvent->id ?? null,
                'already_processed' => !empty($webhookEvent->processed_at),
                'processed_at' => $webhookEvent->processed_at ?? null,
            ]);

            if (!empty($webhookEvent->processed_at)) {
                Log::info('[StripeWebhook] Deduped event', [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                ]);
                return response()->json(['received' => true, 'deduped' => true]);
            }
        } else {
            Log::warning('[StripeWebhook] Event has no ID');
        }

        try {
            switch ($eventType) {
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    Log::info('[StripeWebhook] Routing to syncFromSubscriptionObject', [
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                    ]);
                    $this->syncFromSubscriptionObject($event->data->object);
                    break;

                case 'invoice.paid':
                case 'invoice.payment_failed':
                    Log::info('[StripeWebhook] Routing to syncFromInvoiceObject', [
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                    ]);
                    $this->syncFromInvoiceObject($event->data->object);
                    break;

                case 'checkout.session.completed':
                    Log::info('[StripeWebhook] Routing to syncFromCheckoutSession', [
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                    ]);
                    $this->syncFromCheckoutSession($event->data->object);
                    break;

                default:
                    Log::info('[StripeWebhook] Ignored event type', [
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                    ]);
                    break;
            }

            if ($webhookEvent) {
                $webhookEvent->type = $eventType;
                $webhookEvent->processed_at = now();
                $webhookEvent->save();

                Log::info('[StripeWebhook] Marked event processed', [
                    'event_id' => $eventId,
                    'processed_at' => $webhookEvent->processed_at,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[StripeWebhook] Handler error', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'err' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ]);

            return response()->json(['error' => 'Webhook handler failed'], 500);
        }

        Log::info('[StripeWebhook] Handle success', [
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);

        return response()->json(['received' => true]);
    }

    private function stripe(): StripeClient
    {
        return new StripeClient('sk_test_51SuW7dRzWTT8jmVf0JmTu13BlbYHsTgZpy77gsMrH0xg4bL97SRXXYinKJYMp5yjpaGTTJZLxbzKkzgfOdOuRwdZ00iitGuJql');
    }

    private function syncFromCheckoutSession($session): void
    {
        $stripeSubId = $session->subscription ?? null;
        $customerId  = $session->customer ?? null;
        $metaUserId  = (int)($session->metadata->user_id ?? 0);

        Log::info('[StripeWebhook] Checkout session sync start', [
            'session_id' => $session->id ?? null,
            'customer_id' => $customerId,
            'stripe_sub_id' => $stripeSubId,
            'meta_user_id' => $metaUserId ?: null,
            'payment_status' => $session->payment_status ?? null,
            'mode' => $session->mode ?? null,
        ]);

        if (!$stripeSubId || !$customerId) {
            Log::warning('[StripeWebhook] Checkout session missing customer/subscription', [
                'session_id' => $session->id ?? null,
                'customer_id' => $customerId,
                'stripe_sub_id' => $stripeSubId,
            ]);
            return;
        }

        $user = $metaUserId
            ? User::find($metaUserId)
            : User::where('stripe_customer_id', $customerId)->first();

        Log::info('[StripeWebhook] Checkout session user lookup result', [
            'session_id' => $session->id ?? null,
            'customer_id' => $customerId,
            'meta_user_id' => $metaUserId ?: null,
            'found_user_id' => $user->id ?? null,
        ]);

        if (!$user) {
            Log::warning('[StripeWebhook] No user matched checkout session', [
                'session_id' => $session->id ?? null,
                'customer_id' => $customerId,
                'meta_user_id' => $metaUserId ?: null,
            ]);
            return;
        }

        if (!$user->stripe_customer_id && $customerId) {
            Log::info('[StripeWebhook] Updating user stripe_customer_id from checkout session', [
                'user_id' => $user->id,
                'old_customer_id' => $user->stripe_customer_id,
                'new_customer_id' => $customerId,
            ]);
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSubId, [
            'expand' => ['items.data.price'],
        ]);

        Log::info('[StripeWebhook] Checkout session fetched subscription', [
            'user_id' => $user->id,
            'stripe_sub_id' => $stripeSub->id ?? null,
            'status' => $stripeSub->status ?? null,
            'price_id' => $stripeSub->items->data[0]->price->id ?? null,
        ]);

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function syncFromSubscriptionObject($stripeSub): void
    {
        $customerId = $stripeSub->customer ?? null;
        $metaUserId = (int)($stripeSub->metadata->user_id ?? 0);

        Log::info('[StripeWebhook] Subscription object sync start', [
            'stripe_sub_id' => $stripeSub->id ?? null,
            'customer_id' => $customerId,
            'status' => $stripeSub->status ?? null,
            'cancel_at_period_end' => $stripeSub->cancel_at_period_end ?? null,
            'meta_user_id' => $metaUserId ?: null,
            'has_price_inline' => !empty($stripeSub->items->data[0]->price),
        ]);

        if (!$customerId) {
            Log::warning('[StripeWebhook] Subscription object missing customer', [
                'stripe_sub_id' => $stripeSub->id ?? null,
            ]);
            return;
        }

        $user = User::where('stripe_customer_id', $customerId)->first();

        if (!$user && $metaUserId) {
            $user = User::find($metaUserId);
            Log::info('[StripeWebhook] Subscription object fallback lookup by metadata user_id', [
                'meta_user_id' => $metaUserId,
                'found_user_id' => $user->id ?? null,
            ]);
        }

        Log::info('[StripeWebhook] Subscription object user lookup result', [
            'customer_id' => $customerId,
            'meta_user_id' => $metaUserId ?: null,
            'found_user_id' => $user->id ?? null,
        ]);

        if (!$user) {
            Log::warning('[StripeWebhook] No user matched subscription object', [
                'stripe_sub_id' => $stripeSub->id ?? null,
                'customer_id' => $customerId,
                'meta_user_id' => $metaUserId ?: null,
            ]);
            return;
        }

        if (!$user->stripe_customer_id) {
            Log::info('[StripeWebhook] Updating user stripe_customer_id from subscription object', [
                'user_id' => $user->id,
                'old_customer_id' => $user->stripe_customer_id,
                'new_customer_id' => $customerId,
            ]);
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        $needRetrieve = empty($stripeSub->items->data[0]->price);
        if ($needRetrieve) {
            Log::info('[StripeWebhook] Subscription object missing expanded price; retrieving full subscription', [
                'stripe_sub_id' => $stripeSub->id ?? null,
            ]);

            $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSub->id, [
                'expand' => ['items.data.price'],
            ]);

            Log::info('[StripeWebhook] Subscription object retrieve complete', [
                'stripe_sub_id' => $stripeSub->id ?? null,
                'status' => $stripeSub->status ?? null,
                'price_id' => $stripeSub->items->data[0]->price->id ?? null,
            ]);
        }

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function syncFromInvoiceObject($invoice): void
    {
        $customerId  = $invoice->customer ?? null;
        $stripeSubId = $invoice->subscription
        ?? ($invoice->parent->subscription_details->subscription ?? null)
        ?? ($invoice->parent->subscription ?? null)
        ?? null;

        Log::info('[StripeWebhook] Invoice sync start', [
            'invoice_id' => $invoice->id ?? null,
            'customer_id' => $customerId,
            'stripe_sub_id' => $stripeSubId,
            'paid' => (bool)($invoice->paid ?? false),
            'invoice_status' => $invoice->status ?? null,
            'billing_reason' => $invoice->billing_reason ?? null,
            'amount_paid' => $invoice->amount_paid ?? null,
            'amount_due' => $invoice->amount_due ?? null,
            'currency' => $invoice->currency ?? null,
        ]);

        if (!$customerId || !$stripeSubId) {
            Log::warning('[StripeWebhook] Invoice missing customer/subscription', [
                'invoice_id' => $invoice->id ?? null,
                'customer_id' => $customerId,
                'stripe_sub_id' => $stripeSubId,
            ]);
            return;
        }

        $user = User::where('stripe_customer_id', $customerId)->first();

        Log::info('[StripeWebhook] Invoice user lookup result', [
            'invoice_id' => $invoice->id ?? null,
            'customer_id' => $customerId,
            'found_user_id' => $user->id ?? null,
        ]);

        if (!$user) {
            Log::warning('[StripeWebhook] No user matched invoice customer', [
                'invoice_id' => $invoice->id ?? null,
                'customer_id' => $customerId,
                'stripe_sub_id' => $stripeSubId,
            ]);
            return;
        }

        $local = Subscription::where('user_id', $user->id)->first();

        Log::info('[StripeWebhook] Existing local subscription lookup', [
            'user_id' => $user->id,
            'found_subscription_id' => $local->id ?? null,
            'local_status' => $local->status ?? null,
            'local_stripe_sub_id' => $local->stripe_subscription_id ?? null,
        ]);

        if ($local) {
            if (($invoice->paid ?? false) === true) {
                if (in_array($local->status, ['incomplete', 'past_due', 'unpaid'], true)) {
                    $oldStatus = $local->status;
                    $local->status = 'active';
                    $local->save();

                    Log::info('[StripeWebhook] Local subscription status upgraded from invoice.paid', [
                        'user_id' => $user->id,
                        'subscription_id' => $local->id,
                        'old_status' => $oldStatus,
                        'new_status' => $local->status,
                    ]);
                } else {
                    Log::info('[StripeWebhook] Invoice.paid received but local status unchanged', [
                        'user_id' => $user->id,
                        'subscription_id' => $local->id,
                        'status' => $local->status,
                    ]);
                }
            } else {
                $oldStatus = $local->status;
                $local->status = $local->status === 'canceled' ? $local->status : 'past_due';
                $local->save();

                Log::info('[StripeWebhook] Local subscription status updated from invoice.payment_failed', [
                    'user_id' => $user->id,
                    'subscription_id' => $local->id,
                    'old_status' => $oldStatus,
                    'new_status' => $local->status,
                ]);
            }

            Log::info('[StripeWebhook] Invoice synced (fast path)', [
                'user_id' => $user->id,
                'invoice_id' => $invoice->id ?? null,
                'paid' => (bool)($invoice->paid ?? false),
                'stripe_sub_id' => $stripeSubId,
            ]);

            return;
        }

        Log::info('[StripeWebhook] No local subscription found; retrieving Stripe subscription', [
            'user_id' => $user->id,
            'stripe_sub_id' => $stripeSubId,
        ]);

        $stripeSub = $this->stripe()->subscriptions->retrieve($stripeSubId, [
            'expand' => ['items.data.price'],
        ]);

        Log::info('[StripeWebhook] Stripe subscription retrieved from invoice', [
            'user_id' => $user->id,
            'stripe_sub_id' => $stripeSub->id ?? null,
            'status' => $stripeSub->status ?? null,
            'customer_id' => $stripeSub->customer ?? null,
            'price_id' => $stripeSub->items->data[0]->price->id ?? null,
            'billing_interval' => $stripeSub->items->data[0]->price->recurring->interval ?? null,
        ]);

        $this->upsertUserSubscription($user->id, $customerId, $stripeSub);
    }

    private function upsertUserSubscription(int $userId, ?string $customerId, $stripeSub): void
    {
        Log::info('[StripeWebhook] Upsert start', [
            'user_id' => $userId,
            'customer_id' => $customerId,
            'stripe_sub_id' => $stripeSub->id ?? null,
        ]);

        $item     = $stripeSub->items->data[0] ?? null;
        $priceObj = $item->price ?? null;

        if (!$item) {
            Log::warning('[StripeWebhook] Upsert aborted: no subscription item found', [
                'user_id' => $userId,
                'stripe_sub_id' => $stripeSub->id ?? null,
            ]);
            return;
        }

        if (!$priceObj) {
            Log::warning('[StripeWebhook] Upsert aborted: no price object found on subscription item', [
                'user_id' => $userId,
                'stripe_sub_id' => $stripeSub->id ?? null,
                'item_id' => $item->id ?? null,
            ]);
            return;
        }

        $priceId  = $priceObj->id ?? null;
        $interval = $priceObj->recurring->interval ?? null;
        $currency = $stripeSub->currency ?? ($priceObj->currency ?? null);

        Log::info('[StripeWebhook] Parsed subscription pricing details', [
            'user_id' => $userId,
            'stripe_sub_id' => $stripeSub->id ?? null,
            'price_id' => $priceId,
            'billing_interval' => $interval,
            'currency' => $currency,
        ]);

        $plan = $priceId ? Plan::findByStripePriceId($priceId) : null;

        Log::info('[StripeWebhook] Plan lookup by price_id result', [
            'user_id' => $userId,
            'price_id' => $priceId,
            'found_plan_id' => $plan->id ?? null,
        ]);

        if (!$plan) {
            $fallbackPlan = method_exists(Plan::class, 'premium') ? Plan::premium() : Plan::first();

            Log::info('[StripeWebhook] Fallback plan lookup result', [
                'user_id' => $userId,
                'fallback_plan_id' => $fallbackPlan->id ?? null,
            ]);

            $plan = $fallbackPlan;
        }

        if (!$plan) {
            Log::warning('[StripeWebhook] No plan resolved; upsert aborted', [
                'price_id' => $priceId,
                'user_id' => $userId,
                'stripe_sub_id' => $stripeSub->id ?? null,
            ]);
            return;
        }

        $currentPeriodEndTs =
    $stripeSub->current_period_end
    ?? ($stripeSub->items->data[0]->current_period_end ?? null);

$currentPeriodEnd = $currentPeriodEndTs
    ? now()->setTimestamp((int) $currentPeriodEndTs)
    : null;

        Log::info('[StripeWebhook] Final upsert payload', [
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $stripeSub->id ?? null,
            'status' => $stripeSub->status ?? null,
            'cancel_at_period_end' => (bool)($stripeSub->cancel_at_period_end ?? false),
            'current_period_end' => optional($currentPeriodEnd)->toDateTimeString(),
            'currency' => $currency,
            'billing_interval' => $interval,
            'price_id' => $priceId,
        ]);

        $subscription = Subscription::updateOrCreate(
            ['user_id' => $userId],
            [
                'plan_id'                => $plan->id,
                'stripe_customer_id'     => $customerId,
                'stripe_subscription_id' => $stripeSub->id,
                'status'                 => $stripeSub->status,
                'cancel_at_period_end'   => (bool)($stripeSub->cancel_at_period_end ?? false),
                'current_period_end'     => $currentPeriodEnd,
                'currency'               => $currency,
                'billing_interval'       => $interval,
                'price_id'               => $priceId,
                'metadata'               => [
                    'raw' => [
                        'id' => $stripeSub->id ?? null,
                        'status' => $stripeSub->status ?? null,
                        'collection_method' => $stripeSub->collection_method ?? null,
                    ],
                ],
            ]
        );

        Log::info('[StripeWebhook] Subscription synced', [
            'subscription_id' => $subscription->id ?? null,
            'user_id' => $userId,
            'stripe_sub_id' => $stripeSub->id ?? null,
            'status' => $stripeSub->status ?? null,
            'price_id' => $priceId,
            'billing_interval' => $interval,
            'currency' => $currency,
        ]);
    }
}
