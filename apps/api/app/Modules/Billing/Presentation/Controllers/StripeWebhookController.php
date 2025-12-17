<?php

declare(strict_types=1);

namespace App\Modules\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Billing\Notifications\AdminPaymentAlertNotification;
use App\Modules\Billing\Notifications\InvoicePaidNotification;
use App\Modules\Billing\Notifications\PaymentFailedNotification;
use App\Modules\Billing\Notifications\PaymentSucceededNotification;
use App\Modules\Billing\Notifications\SubscriptionCancelledNotification;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class StripeWebhookController extends Controller
{
    /**
     * Handle incoming Stripe webhooks.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (empty($webhookSecret)) {
            Log::error('Stripe webhook secret is not configured');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        if ($signature === null) {
            Log::warning('Missing Stripe-Signature header');

            return response()->json(['error' => 'Missing signature'], 400);
        }

        try {
            /** @var array<string, mixed> $event */
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret)->toArray();
        } catch (SignatureVerificationException $e) {
            Log::warning('Invalid Stripe webhook signature', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Failed to parse Stripe webhook', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $eventType = $event['type'] ?? '';
        $eventId = $event['id'] ?? '';

        Log::info('Stripe webhook received', ['type' => $eventType, 'id' => $eventId]);

        /** @var array<string, mixed> $data */
        $data = $event['data']['object'] ?? [];

        match ($eventType) {
            'customer.subscription.created' => $this->handleSubscriptionCreated($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            'invoice.paid' => $this->handleInvoicePaid($data),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data),
            'invoice.finalized' => $this->handleInvoiceFinalized($data),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($data),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($data),
            'charge.refunded' => $this->handleChargeRefunded($data),
            default => Log::info('Unhandled Stripe webhook type', ['type' => $eventType]),
        };

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle customer.subscription.created event.
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    private function handleSubscriptionCreated(array $stripeSubscription): void
    {
        $stripeSubId = $stripeSubscription['id'] ?? null;
        $stripeCustomerId = $stripeSubscription['customer'] ?? null;

        // Find existing subscription by Stripe ID or customer ID
        $subscription = TenantSubscription::where('stripe_subscription_id', $stripeSubId)
            ->orWhere('stripe_customer_id', $stripeCustomerId)
            ->first();

        if (! $subscription) {
            Log::warning('No matching subscription found for Stripe subscription', [
                'stripe_subscription_id' => $stripeSubId,
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            return;
        }

        $subscription->update([
            'stripe_subscription_id' => $stripeSubId,
            'status' => $this->mapStripeStatus((string) ($stripeSubscription['status'] ?? 'active')),
            'current_period_start' => isset($stripeSubscription['current_period_start'])
                ? \Carbon\Carbon::createFromTimestamp((int) $stripeSubscription['current_period_start'])
                : null,
            'current_period_end' => isset($stripeSubscription['current_period_end'])
                ? \Carbon\Carbon::createFromTimestamp((int) $stripeSubscription['current_period_end'])
                : null,
        ]);

        Log::info('Subscription created via webhook', ['subscription_id' => $subscription->id]);
    }

    /**
     * Handle customer.subscription.updated event.
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    private function handleSubscriptionUpdated(array $stripeSubscription): void
    {
        $stripeSubId = $stripeSubscription['id'] ?? null;

        $subscription = TenantSubscription::where('stripe_subscription_id', $stripeSubId)->first();

        if (! $subscription) {
            Log::warning('Subscription not found for update', [
                'stripe_subscription_id' => $stripeSubId,
            ]);

            return;
        }

        $updateData = [
            'status' => $this->mapStripeStatus((string) ($stripeSubscription['status'] ?? 'active')),
            'current_period_start' => isset($stripeSubscription['current_period_start'])
                ? \Carbon\Carbon::createFromTimestamp((int) $stripeSubscription['current_period_start'])
                : null,
            'current_period_end' => isset($stripeSubscription['current_period_end'])
                ? \Carbon\Carbon::createFromTimestamp((int) $stripeSubscription['current_period_end'])
                : null,
        ];

        // Handle cancellation
        if (! empty($stripeSubscription['cancel_at_period_end'])) {
            $updateData['status'] = SubscriptionStatus::Cancelling;
            if (isset($stripeSubscription['current_period_end'])) {
                $updateData['ends_at'] = \Carbon\Carbon::createFromTimestamp(
                    (int) $stripeSubscription['current_period_end']
                );
            }
        }

        // Handle actual cancellation
        if (! empty($stripeSubscription['canceled_at'])) {
            $updateData['cancelled_at'] = \Carbon\Carbon::createFromTimestamp(
                (int) $stripeSubscription['canceled_at']
            );
        }

        $subscription->update($updateData);

        Log::info('Subscription updated via webhook', ['subscription_id' => $subscription->id]);
    }

    /**
     * Handle customer.subscription.deleted event.
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    private function handleSubscriptionDeleted(array $stripeSubscription): void
    {
        $stripeSubId = $stripeSubscription['id'] ?? null;

        $subscription = TenantSubscription::where('stripe_subscription_id', $stripeSubId)->first();

        if (! $subscription) {
            Log::warning('Subscription not found for deletion', [
                'stripe_subscription_id' => $stripeSubId,
            ]);

            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'ends_at' => now(),
        ]);

        // Send cancellation notification to tenant
        $this->notifyTenant($subscription->tenant_id, new SubscriptionCancelledNotification($subscription));

        Log::info('Subscription cancelled via webhook', ['subscription_id' => $subscription->id]);
    }

    /**
     * Handle invoice.paid event.
     *
     * @param  array<string, mixed>  $stripeInvoice
     */
    private function handleInvoicePaid(array $stripeInvoice): void
    {
        $stripeInvoiceId = $stripeInvoice['id'] ?? null;

        // Find or create invoice
        $invoice = Invoice::where('stripe_invoice_id', $stripeInvoiceId)->first();

        $amountPaid = ((int) ($stripeInvoice['amount_paid'] ?? 0)) / 100;

        if ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'amount_paid' => $amountPaid,
                'amount_due' => 0,
            ]);
        }

        // Create payment record
        $stripeSubscriptionId = $stripeInvoice['subscription'] ?? null;
        $paymentIntentId = $stripeInvoice['payment_intent'] ?? null;

        $subscription = $stripeSubscriptionId
            ? TenantSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;

        if ($subscription && $paymentIntentId) {
            $payment = Payment::updateOrCreate(
                ['provider_payment_id' => $paymentIntentId],
                [
                    'tenant_id' => $subscription->tenant_id,
                    'invoice_id' => $invoice?->id,
                    'provider' => PaymentProviderCode::Stripe,
                    'status' => PaymentStatus::Succeeded,
                    'amount' => $amountPaid,
                    'fee' => 0,
                    'net_amount' => $amountPaid,
                    'currency' => strtoupper((string) ($stripeInvoice['currency'] ?? 'EUR')),
                    'refunded_amount' => 0,
                    'paid_at' => now(),
                ]
            );

            // Update subscription last payment
            $subscription->update([
                'last_payment_at' => now(),
                'status' => SubscriptionStatus::Active,
            ]);

            // Send notifications
            if ($invoice) {
                $this->notifyTenant($subscription->tenant_id, new InvoicePaidNotification($invoice));
            }
            $this->notifyTenant($subscription->tenant_id, new PaymentSucceededNotification($payment));
            $this->notifyAdmins(new AdminPaymentAlertNotification(
                'payment_succeeded',
                $payment,
                $this->getTenantName($subscription->tenant_id),
            ));
        }

        Log::info('Invoice paid via webhook', [
            'stripe_invoice_id' => $stripeInvoiceId,
            'amount' => $amountPaid,
        ]);
    }

    /**
     * Handle invoice.payment_failed event.
     *
     * @param  array<string, mixed>  $stripeInvoice
     */
    private function handleInvoicePaymentFailed(array $stripeInvoice): void
    {
        $stripeInvoiceId = $stripeInvoice['id'] ?? null;

        $invoice = Invoice::where('stripe_invoice_id', $stripeInvoiceId)->first();

        if ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Overdue,
            ]);
        }

        // Update subscription status
        $stripeSubscriptionId = $stripeInvoice['subscription'] ?? null;
        $subscription = $stripeSubscriptionId
            ? TenantSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::PastDue,
            ]);

            // Send payment failed notification to tenant
            if ($invoice) {
                $errorMessage = (string) ($stripeInvoice['last_payment_error']['message'] ?? 'Payment declined');
                $this->notifyTenant($subscription->tenant_id, new PaymentFailedNotification($invoice, $errorMessage));
            }

            // Create a payment record for tracking failed payments
            $paymentIntentId = $stripeInvoice['payment_intent'] ?? null;
            if ($paymentIntentId) {
                $payment = Payment::updateOrCreate(
                    ['provider_payment_id' => $paymentIntentId],
                    [
                        'tenant_id' => $subscription->tenant_id,
                        'invoice_id' => $invoice?->id,
                        'provider' => PaymentProviderCode::Stripe,
                        'status' => PaymentStatus::Failed,
                        'amount' => ((int) ($stripeInvoice['amount_due'] ?? 0)) / 100,
                        'fee' => 0,
                        'net_amount' => 0,
                        'currency' => strtoupper((string) ($stripeInvoice['currency'] ?? 'EUR')),
                        'refunded_amount' => 0,
                        'error_message' => (string) ($stripeInvoice['last_payment_error']['message'] ?? 'Payment failed'),
                    ]
                );

                // Notify admins
                $this->notifyAdmins(new AdminPaymentAlertNotification(
                    'payment_failed',
                    $payment,
                    $this->getTenantName($subscription->tenant_id),
                    (string) ($stripeInvoice['last_payment_error']['message'] ?? 'Payment failed'),
                ));
            }
        }

        Log::warning('Invoice payment failed', [
            'stripe_invoice_id' => $stripeInvoiceId,
        ]);
    }

    /**
     * Handle invoice.finalized event.
     *
     * @param  array<string, mixed>  $stripeInvoice
     */
    private function handleInvoiceFinalized(array $stripeInvoice): void
    {
        $stripeSubscriptionId = $stripeInvoice['subscription'] ?? null;
        $subscription = $stripeSubscriptionId
            ? TenantSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first()
            : null;

        if (! $subscription) {
            return;
        }

        $stripeInvoiceId = $stripeInvoice['id'] ?? null;
        $discounts = $stripeInvoice['total_discount_amounts'] ?? [];
        $discountAmount = ! empty($discounts[0]['amount']) ? ((int) $discounts[0]['amount']) / 100 : 0;

        // Create invoice record if not exists
        Invoice::updateOrCreate(
            ['stripe_invoice_id' => $stripeInvoiceId],
            [
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'number' => (string) ($stripeInvoice['number'] ?? $this->generateInvoiceNumber($subscription->tenant_id)),
                'status' => InvoiceStatus::Pending,
                'subtotal' => ((int) ($stripeInvoice['subtotal'] ?? 0)) / 100,
                'tax_amount' => ((int) ($stripeInvoice['tax'] ?? 0)) / 100,
                'discount_amount' => $discountAmount,
                'total' => ((int) ($stripeInvoice['total'] ?? 0)) / 100,
                'amount_paid' => ((int) ($stripeInvoice['amount_paid'] ?? 0)) / 100,
                'amount_due' => ((int) ($stripeInvoice['amount_due'] ?? 0)) / 100,
                'currency' => strtoupper((string) ($stripeInvoice['currency'] ?? 'EUR')),
                'invoice_date' => isset($stripeInvoice['created'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $stripeInvoice['created'])
                    : now(),
                'due_date' => isset($stripeInvoice['due_date'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $stripeInvoice['due_date'])
                    : now()->addDays(30),
                'period_start' => isset($stripeInvoice['period_start'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $stripeInvoice['period_start'])
                    : null,
                'period_end' => isset($stripeInvoice['period_end'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $stripeInvoice['period_end'])
                    : null,
            ]
        );

        Log::info('Invoice finalized via webhook', [
            'stripe_invoice_id' => $stripeInvoiceId,
        ]);
    }

    /**
     * Handle payment_intent.succeeded event.
     *
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handlePaymentIntentSucceeded(array $paymentIntent): void
    {
        $paymentIntentId = $paymentIntent['id'] ?? null;

        // Update existing payment if exists
        $payment = Payment::where('provider_payment_id', $paymentIntentId)->first();

        if ($payment) {
            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
            ]);
        }

        Log::info('Payment intent succeeded', [
            'payment_intent_id' => $paymentIntentId,
            'amount' => ((int) ($paymentIntent['amount'] ?? 0)) / 100,
        ]);
    }

    /**
     * Handle payment_intent.payment_failed event.
     *
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handlePaymentIntentFailed(array $paymentIntent): void
    {
        $paymentIntentId = $paymentIntent['id'] ?? null;

        $payment = Payment::where('provider_payment_id', $paymentIntentId)->first();

        /** @var array<string, mixed>|null $lastError */
        $lastError = $paymentIntent['last_payment_error'] ?? null;

        if ($payment) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'error_code' => $lastError['code'] ?? null,
                'error_message' => $lastError['message'] ?? null,
            ]);
        }

        Log::warning('Payment intent failed', [
            'payment_intent_id' => $paymentIntentId,
            'error' => $lastError['message'] ?? 'Unknown error',
        ]);
    }

    /**
     * Handle charge.refunded event.
     *
     * @param  array<string, mixed>  $charge
     */
    private function handleChargeRefunded(array $charge): void
    {
        $paymentIntentId = $charge['payment_intent'] ?? null;

        $payment = $paymentIntentId
            ? Payment::where('provider_payment_id', $paymentIntentId)->first()
            : null;

        if (! $payment) {
            Log::warning('Payment not found for refund', [
                'payment_intent' => $paymentIntentId,
            ]);

            return;
        }

        $refundedAmount = ((int) ($charge['amount_refunded'] ?? 0)) / 100;

        $payment->update([
            'refunded_amount' => $refundedAmount,
            'refunded_at' => now(),
            'status' => $refundedAmount >= (float) $payment->amount
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
        ]);

        // Notify admins about refund
        $this->notifyAdmins(new AdminPaymentAlertNotification(
            'refund_processed',
            $payment,
            $this->getTenantName($payment->tenant_id),
            "Refunded amount: {$refundedAmount}",
        ));

        Log::info('Charge refunded', [
            'payment_id' => $payment->id,
            'refunded_amount' => $refundedAmount,
        ]);
    }

    /**
     * Map Stripe subscription status to our enum.
     */
    private function mapStripeStatus(string $stripeStatus): SubscriptionStatus
    {
        return match ($stripeStatus) {
            'trialing' => SubscriptionStatus::Trial,
            'active' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'unpaid' => SubscriptionStatus::Unpaid,
            'canceled' => SubscriptionStatus::Cancelled,
            'incomplete', 'incomplete_expired' => SubscriptionStatus::Expired,
            'paused' => SubscriptionStatus::Paused,
            default => SubscriptionStatus::Active,
        };
    }

    /**
     * Generate an invoice number.
     */
    private function generateInvoiceNumber(string $tenantId): string
    {
        $prefix = 'INV';
        $year = date('Y');
        $count = Invoice::where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $year, $count);
    }

    /**
     * Send notification to tenant.
     */
    private function notifyTenant(string $tenantId, \Illuminate\Notifications\Notification $notification): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant || empty($tenant->email)) {
            Log::warning('Cannot send notification: tenant not found or has no email', [
                'tenant_id' => $tenantId,
            ]);

            return;
        }

        try {
            Notification::route('mail', $tenant->email)->notify($notification);
        } catch (\Exception $e) {
            Log::error('Failed to send tenant notification', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification to all active super admins.
     */
    private function notifyAdmins(\Illuminate\Notifications\Notification $notification): void
    {
        try {
            $admins = SuperAdmin::where('is_active', true)->get();

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send admin notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get tenant name for admin notifications.
     */
    private function getTenantName(string $tenantId): string
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return "Tenant #{$tenantId}";
        }

        return $tenant->name ?? $tenant->legal_name ?? "Tenant #{$tenantId}";
    }
}
