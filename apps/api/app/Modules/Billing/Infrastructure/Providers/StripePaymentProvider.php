<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Providers;

use App\Modules\Billing\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Billing\Domain\ValueObjects\PaymentResult;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund as StripeRefund;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Stripe payment provider for card and other online payments.
 */
final class StripePaymentProvider implements PaymentProviderInterface
{
    private string $secretKey;

    private string $webhookSecret;

    public function __construct()
    {
        $this->secretKey = (string) config('services.stripe.secret');
        $this->webhookSecret = (string) config('services.stripe.webhook_secret');

        Stripe::setApiKey($this->secretKey);
    }

    public function getCode(): PaymentProviderCode
    {
        return PaymentProviderCode::Stripe;
    }

    public function getName(): string
    {
        return 'Stripe';
    }

    public function isAvailable(): bool
    {
        return PaymentProviderCode::Stripe->isConfigured();
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->getSupportedCurrencies(), true);
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        // Stripe supports 135+ currencies, here are the most common
        return [
            'EUR', 'USD', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY',
            'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON',
        ];
    }

    /**
     * Create a Stripe PaymentIntent.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createPayment(
        Money $amount,
        string $description,
        array $metadata = [],
    ): PaymentResult {
        if (! $this->isAvailable()) {
            return PaymentResult::failed('Stripe is not configured');
        }

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount->toCents(),
                'currency' => strtolower($amount->currency),
                'description' => $description,
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            // Check if requires immediate action
            if ($paymentIntent->status === 'requires_action') {
                return PaymentResult::requiresAction(
                    paymentId: $paymentIntent->id,
                    clientSecret: $paymentIntent->client_secret ?? '',
                    actionUrl: $paymentIntent->next_action?->redirect_to_url->url ?? '',
                );
            }

            if ($paymentIntent->status === 'succeeded') {
                return PaymentResult::success(
                    paymentId: $paymentIntent->id,
                    message: 'Payment completed',
                );
            }

            // Payment requires confirmation (client-side)
            return new PaymentResult(
                success: false,
                status: PaymentStatus::RequiresAction,
                paymentId: $paymentIntent->id,
                clientSecret: $paymentIntent->client_secret,
                requiresAction: true,
                actionType: 'confirm_card_payment',
                message: 'Payment requires confirmation',
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe payment creation failed', [
                'error' => $e->getMessage(),
                'amount' => $amount->amount,
                'currency' => $amount->currency,
            ]);

            return PaymentResult::failed(
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
            );
        }
    }

    public function capturePayment(string $paymentId): PaymentResult
    {
        if (! $this->isAvailable()) {
            return PaymentResult::failed('Stripe is not configured');
        }

        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            $paymentIntent->capture();

            return PaymentResult::success(
                paymentId: $paymentId,
                message: 'Payment captured',
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe capture failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed(
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
            );
        }
    }

    public function cancelPayment(string $paymentId): PaymentResult
    {
        if (! $this->isAvailable()) {
            return PaymentResult::failed('Stripe is not configured');
        }

        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);
            $paymentIntent->cancel();

            return new PaymentResult(
                success: true,
                status: PaymentStatus::Cancelled,
                paymentId: $paymentId,
                message: 'Payment cancelled',
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe cancel failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed(
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
            );
        }
    }

    public function refund(string $paymentId, ?Money $amount = null): PaymentResult
    {
        if (! $this->isAvailable()) {
            return PaymentResult::failed('Stripe is not configured');
        }

        try {
            $params = ['payment_intent' => $paymentId];

            if ($amount !== null) {
                $params['amount'] = $amount->toCents();
            }

            $refund = StripeRefund::create($params);

            return PaymentResult::success(
                paymentId: $refund->id,
                message: 'Refund processed',
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe refund failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed(
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
            );
        }
    }

    public function getPaymentStatus(string $paymentId): PaymentResult
    {
        if (! $this->isAvailable()) {
            return PaymentResult::failed('Stripe is not configured');
        }

        try {
            $paymentIntent = PaymentIntent::retrieve($paymentId);

            $status = match ($paymentIntent->status) {
                'succeeded' => PaymentStatus::Succeeded,
                'processing' => PaymentStatus::Processing,
                'requires_action' => PaymentStatus::RequiresAction,
                'requires_payment_method' => PaymentStatus::RequiresAction,
                'canceled' => PaymentStatus::Cancelled,
                default => PaymentStatus::Pending,
            };

            $success = $status === PaymentStatus::Succeeded;

            return new PaymentResult(
                success: $success,
                status: $status,
                paymentId: $paymentId,
                message: "Payment status: {$paymentIntent->status}",
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe status check failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed(
                message: $e->getMessage(),
                errorCode: $e->getStripeCode(),
            );
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        $signature = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';

        try {
            Webhook::constructEvent($payload, $signature, $this->webhookSecret);

            return true;
        } catch (\Exception $e) {
            Log::warning('Stripe webhook verification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array
    {
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return [];
        }

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

        return [
            'event_type' => $type,
            'payment_id' => $data['id'] ?? null,
            'status' => $this->mapWebhookStatus($type, $data),
            'amount' => isset($data['amount']) ? $data['amount'] / 100 : null,
            'currency' => $data['currency'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'raw' => $event,
        ];
    }

    /**
     * Map Stripe webhook event to payment status.
     *
     * @param  array<string, mixed>  $data
     */
    private function mapWebhookStatus(string $eventType, array $data): ?PaymentStatus
    {
        return match ($eventType) {
            'payment_intent.succeeded' => PaymentStatus::Succeeded,
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            'payment_intent.canceled' => PaymentStatus::Cancelled,
            'payment_intent.processing' => PaymentStatus::Processing,
            'payment_intent.requires_action' => PaymentStatus::RequiresAction,
            'charge.refunded' => PaymentStatus::Refunded,
            default => null,
        };
    }

    /**
     * Create a Stripe Customer for a tenant.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createCustomer(
        string $email,
        string $name,
        array $metadata = [],
    ): ?string {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $customer = \Stripe\Customer::create([
                'email' => $email,
                'name' => $name,
                'metadata' => $metadata,
            ]);

            return $customer->id;
        } catch (ApiErrorException $e) {
            Log::error('Stripe customer creation failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a subscription in Stripe.
     *
     * @return array<string, mixed>|null
     */
    public function createSubscription(
        string $customerId,
        string $priceId,
        ?int $trialDays = null,
    ): ?array {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $params = [
                'customer' => $customerId,
                'items' => [['price' => $priceId]],
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent'],
            ];

            if ($trialDays !== null && $trialDays > 0) {
                $params['trial_period_days'] = $trialDays;
            }

            $subscription = \Stripe\Subscription::create($params);

            $latestInvoice = $subscription->latest_invoice;
            $clientSecret = null;
            if ($latestInvoice instanceof \Stripe\Invoice) {
                /** @phpstan-ignore property.notFound */
                $paymentIntent = $latestInvoice->payment_intent;
                if ($paymentIntent instanceof \Stripe\PaymentIntent) {
                    $clientSecret = $paymentIntent->client_secret;
                }
            }

            return [
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'client_secret' => $clientSecret,
                /** @phpstan-ignore property.notFound */
                'current_period_end' => $subscription->current_period_end,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Stripe subscription creation failed', [
                'customer_id' => $customerId,
                'price_id' => $priceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancel a Stripe subscription.
     */
    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            if ($immediately) {
                $subscription->cancel();
            } else {
                $subscription->update($subscriptionId, [
                    'cancel_at_period_end' => true,
                ]);
            }

            return true;
        } catch (ApiErrorException $e) {
            Log::error('Stripe subscription cancellation failed', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
