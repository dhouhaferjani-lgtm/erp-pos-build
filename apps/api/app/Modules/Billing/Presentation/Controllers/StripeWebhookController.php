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
use App\Shared\Architecture\CrossTenantRoute;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * @cross-tenant-by-design Webhook entry — tenant resolution shape (b) sub-form
 * (master plan §8): tenant is derived from the verified Stripe payload via
 * globally-unique Stripe-issued resource IDs (sub_*, in_*, pi_*) → resource
 * lookup → resource.tenant_id stamps every downstream Notification + DB write.
 *
 * Signature verification fires inline at handle() lines 53-64 BEFORE any DB
 * read, via Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret)
 * with the global STRIPE_WEBHOOK_SECRET. Failure returns 400 with no DB
 * side-effects. Stripe's SDK enforces timestamp tolerance + HMAC-SHA256 in
 * constant time — no replay surface.
 *
 * No CompanyContext::setCompanyId() binding: tenant_subscriptions,
 * billing_invoices, billing_payments are platform-level public-schema tables
 * (see migrations 2025_12_16_10000{1,2,4}_*) tenant-isolated by tenant_id
 * column rather than schema, and the resolved $resource->tenant_id stamps
 * every downstream notification/update directly. The "or equivalent"
 * mechanism in master plan §8 step 3 covers this shape.
 *
 * Defense-in-depth: tenant_subscriptions.stripe_subscription_id and
 * billing_invoices.stripe_invoice_id carry DB UNIQUE constraints
 * (migrations lines 35 + 60). billing_payments(provider, provider_payment_id)
 * carries a UNIQUE constraint as of api.platform-integration cluster
 * (migration 2026_05_08_000002_*), closing Finding F. Resolver code uses
 * `resolveStripePayment()` (added below) which:
 *   (a) filters by `provider = stripe` so a foreign-provider Payment that
 *       contrives a `pi_*` collision is never returned here; AND
 *   (b) calls ->sole() over ->first() so a >1-row state (only possible if
 *       the UNIQUE is ever dropped) crashes loud rather than silently
 *       picking one row.
 *
 * Out-of-scope concerns (deferred per the same observations doc):
 *   - Finding D: handleSubscriptionCreated orWhere(stripe_customer_id)
 *     fallback — logic-correctness within tenant, not isolation.
 *   - Finding E: missing event-id idempotency — Stripe redelivers; defer
 *     to future api.billing hardening cluster.
 */
final class StripeWebhookController extends Controller
{
    /**
     * Handle incoming Stripe webhooks.
     */
    #[CrossTenantRoute(reason: 'Stripe webhook entry: tenant resolved from the verified Stripe-signed payload via globally-unique resource IDs (sub_*/in_*/pi_*) per master plan §8 shape (b); inline signature verification via Stripe\\Webhook::constructEvent fires BEFORE any DB read; see class-level @cross-tenant-by-design annotation (lines 32-67) for the full tenant-resolution proof + defense-in-depth UNIQUE-constraint pairing from api.platform-integration cluster.')]
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
                ? Carbon::createFromTimestamp((int) $stripeSubscription['current_period_start'])
                : null,
            'current_period_end' => isset($stripeSubscription['current_period_end'])
                ? Carbon::createFromTimestamp((int) $stripeSubscription['current_period_end'])
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
                ? Carbon::createFromTimestamp((int) $stripeSubscription['current_period_start'])
                : null,
            'current_period_end' => isset($stripeSubscription['current_period_end'])
                ? Carbon::createFromTimestamp((int) $stripeSubscription['current_period_end'])
                : null,
        ];

        // Handle cancellation
        if (! empty($stripeSubscription['cancel_at_period_end'])) {
            $updateData['status'] = SubscriptionStatus::Cancelling;
            if (isset($stripeSubscription['current_period_end'])) {
                $updateData['ends_at'] = Carbon::createFromTimestamp(
                    (int) $stripeSubscription['current_period_end']
                );
            }
        }

        // Handle actual cancellation
        if (! empty($stripeSubscription['canceled_at'])) {
            $updateData['cancelled_at'] = Carbon::createFromTimestamp(
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
            // Provider-filter discipline (Codex round-1 BLOCK-NOVEL).
            // The (provider, provider_payment_id) UNIQUE constraint
            // intentionally allows the same external id across different
            // providers, so the lookup attributes MUST also pin the
            // provider — otherwise a PayPal/Klarna/etc. row with the same
            // `pi_*` value would be matched and overwritten as Stripe.
            // Pair with resolveStripePayment() at the bottom of the file
            // (read-side) — this is the create/reconcile-side pair.
            $payment = Payment::updateOrCreate(
                [
                    'provider' => PaymentProviderCode::Stripe->value,
                    'provider_payment_id' => $paymentIntentId,
                ],
                [
                    'tenant_id' => $subscription->tenant_id,
                    'invoice_id' => $invoice?->id,
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
                // Provider-filter discipline (Codex round-1 BLOCK-NOVEL),
                // mirrors handleInvoicePaid() above — the lookup MUST pin
                // `provider = stripe` so a foreign-provider row sharing
                // `pi_*` is not overwritten as Stripe.
                $payment = Payment::updateOrCreate(
                    [
                        'provider' => PaymentProviderCode::Stripe->value,
                        'provider_payment_id' => $paymentIntentId,
                    ],
                    [
                        'tenant_id' => $subscription->tenant_id,
                        'invoice_id' => $invoice?->id,
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
                    ? Carbon::createFromTimestamp((int) $stripeInvoice['created'])
                    : now(),
                'due_date' => isset($stripeInvoice['due_date'])
                    ? Carbon::createFromTimestamp((int) $stripeInvoice['due_date'])
                    : now()->addDays(30),
                'period_start' => isset($stripeInvoice['period_start'])
                    ? Carbon::createFromTimestamp((int) $stripeInvoice['period_start'])
                    : null,
                'period_end' => isset($stripeInvoice['period_end'])
                    ? Carbon::createFromTimestamp((int) $stripeInvoice['period_end'])
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

        // Provider-filter discipline + collision-fail-loud (Finding F).
        // Pre-fix: where('provider_payment_id', $pi)->first() — could
        // resolve to a foreign-provider Payment that happened to share
        // the same external id, AND silently picked one of multiple
        // matches if a UNIQUE-rollback ever occurred.
        $payment = $this->resolveStripePayment($paymentIntentId);

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

        $payment = $this->resolveStripePayment($paymentIntentId);

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
            ? $this->resolveStripePayment($paymentIntentId)
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

    /**
     * Resolve a Stripe-provider Payment by `pi_*` id with two
     * defense-in-depth properties (api.platform-integration cluster,
     * Finding F closure):
     *   1. Filter by `provider = stripe` so a foreign-provider Payment
     *      that contrived a `pi_*` collision is never resolved here.
     *   2. ->sole() over ->first() so a >1-row state (only possible if
     *      the new (provider, provider_payment_id) UNIQUE constraint is
     *      ever dropped) crashes loud rather than silently picking one.
     *
     * Returns null in the legitimate "not yet reconciled" case (Stripe
     * webhook redelivery before the local Payment was inserted, or
     * after it was soft-deleted) — a graceful skip path that does NOT
     * crash the webhook delivery.
     */
    private function resolveStripePayment(?string $paymentIntentId): ?Payment
    {
        if ($paymentIntentId === null || $paymentIntentId === '') {
            return null;
        }

        try {
            return Payment::query()
                ->where('provider', PaymentProviderCode::Stripe->value)
                ->where('provider_payment_id', $paymentIntentId)
                ->sole();
        } catch (ModelNotFoundException) {
            return null;
        }
        // Note: MultipleRecordsFoundException intentionally NOT caught.
        // It signals the (provider, provider_payment_id) UNIQUE was
        // violated — a data-integrity emergency that must alert.
    }
}
