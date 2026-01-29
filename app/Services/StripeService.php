<?php
// app/Services/StripeService.php
namespace App\Services;

use Stripe\StripeClient;
use App\Models\User;
use App\Models\Plan;

class StripeService
{
    public function client(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    public function getOrCreateCustomer(User $user): string
    {
        if ($user->stripe_customer_id) return $user->stripe_customer_id;

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'name' => $user->name ?? null,
            'metadata' => ['user_id' => (string)$user->id],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    public function createPremiumCheckout(User $user, string $cycle, string $currency, string $successUrl, string $cancelUrl): array
    {
        $plan = Plan::premium();

        $cycle = $cycle === 'annual' ? 'annual' : 'monthly';
        $currency = strtolower($currency);
        $priceId = $plan->stripe_prices[$currency][$cycle] ?? null;

        if (!$priceId) {
            throw new \RuntimeException("Stripe price not configured for {$currency} {$cycle}");
        }

        $customerId = $this->getOrCreateCustomer($user);

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [[ 'price' => $priceId, 'quantity' => 1 ]],
            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string)$user->id,
            'metadata' => [
                'user_id' => (string)$user->id,
                'plan' => 'premium',
                'cycle' => $cycle,
                'currency' => $currency,
            ],
        ]);

        return ['id' => $session->id, 'url' => $session->url];
    }
}
