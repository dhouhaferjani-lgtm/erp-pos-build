# Payment Providers Integration Guide

> Multi-region payment processing for AutoERP SaaS

This document details the payment integration strategy supporting offline payments for Tunisia and Stripe for European markets.

---

## Overview

AutoERP operates in multiple regions with different payment requirements:

| Region | Primary Method | Secondary | Challenges |
|--------|---------------|-----------|------------|
| **Tunisia** | Bank Transfer | Cash/Check | No online payment gateways, manual reconciliation |
| **France** | Stripe (Cards) | SEPA | VAT compliance, Factur-X invoicing |
| **EU** | Stripe (Cards) | SEPA, Local | Multi-currency, country VAT rates |

---

## Architecture

### Provider Abstraction

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Invoice;
use App\Modules\Admin\Domain\Subscription;
use App\Modules\Admin\Domain\ValueObjects\Money;
use App\Modules\Admin\Domain\ValueObjects\PaymentResult;
use App\Modules\Admin\Domain\ValueObjects\RefundResult;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;

interface PaymentProviderInterface
{
    /**
     * Get provider identifier.
     */
    public function getCode(): string;

    /**
     * Check if provider supports the given country.
     */
    public function supportsCountry(string $countryCode): bool;

    /**
     * Check if provider supports automatic recurring payments.
     */
    public function supportsRecurring(): bool;

    /**
     * Create a customer record in the payment provider.
     */
    public function createCustomer(Tenant $tenant): string;

    /**
     * Create a new subscription.
     */
    public function createSubscription(
        Tenant $tenant,
        Plan $plan,
        ?string $paymentMethodId = null
    ): Subscription;

    /**
     * Cancel an existing subscription.
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $immediate = false
    ): void;

    /**
     * Process a one-time payment.
     */
    public function processPayment(Invoice $invoice): PaymentResult;

    /**
     * Record a manual/offline payment.
     */
    public function recordManualPayment(
        Invoice $invoice,
        Money $amount,
        string $paymentMethod,
        ?string $reference = null,
        ?string $notes = null
    ): PaymentResult;

    /**
     * Issue a refund.
     */
    public function refund(Payment $payment, Money $amount): RefundResult;

    /**
     * Get all invoices for a tenant.
     */
    public function getInvoices(Tenant $tenant): Collection;

    /**
     * Generate a checkout URL for subscription.
     */
    public function getCheckoutUrl(Tenant $tenant, Plan $plan): ?string;

    /**
     * Generate a portal URL for billing management.
     */
    public function getBillingPortalUrl(Tenant $tenant): ?string;

    /**
     * Handle incoming webhook.
     */
    public function handleWebhook(array $payload, string $signature): void;
}
```

### Provider Resolution

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Tenant\Domain\Tenant;

final class PaymentProviderResolver
{
    /**
     * @param array<string, PaymentProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    public function resolve(Tenant $tenant): PaymentProviderInterface
    {
        // Check if tenant has a specific provider configured
        $config = $tenant->paymentConfig;
        if ($config !== null && isset($this->providers[$config->provider_code])) {
            return $this->providers[$config->provider_code];
        }

        // Resolve by country
        $countryCode = $tenant->country_code;

        foreach ($this->providers as $provider) {
            if ($provider->supportsCountry($countryCode)) {
                return $provider;
            }
        }

        // Fallback to default
        return $this->providers[$this->defaultProvider];
    }

    public function get(string $code): PaymentProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new \InvalidArgumentException("Unknown payment provider: {$code}");
        }

        return $this->providers[$code];
    }
}
```

---

## Provider: Manual (Tunisia)

### Overview

For Tunisia and other markets without online payment infrastructure, we use a manual payment provider that supports:

- **Bank transfers** (virement bancaire)
- **Checks** (chèque)
- **Cash** (espèces) - for in-person payments

### Workflow

```
┌──────────────────────────────────────────────────────────────────────┐
│                         MANUAL PAYMENT FLOW                          │
└──────────────────────────────────────────────────────────────────────┘

1. INVOICE GENERATION
   ┌─────────────┐
   │   Tenant    │──▶ Subscription due / Trial ending
   └─────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  System generates proforma invoice  │
   │  - Invoice number                   │
   │  - Due date                         │
   │  - Bank account details             │
   │  - Payment reference                │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Email sent to tenant with PDF      │
   └─────────────────────────────────────┘

2. PAYMENT
   ┌─────────────┐
   │   Tenant    │──▶ Makes bank transfer with reference
   └─────────────┘

3. RECONCILIATION
   ┌─────────────────────────────────────┐
   │  Super Admin receives bank statement │
   │  or payment notification             │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Super Admin marks invoice as paid  │
   │  in dashboard                        │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  System activates subscription      │
   │  Sends receipt to tenant            │
   └─────────────────────────────────────┘
```

### Implementation

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Providers;

use App\Modules\Admin\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Admin\Domain\Invoice;
use App\Modules\Admin\Domain\InvoiceStatus;
use App\Modules\Admin\Domain\Payment;
use App\Modules\Admin\Domain\PaymentStatus;
use App\Modules\Admin\Domain\Subscription;
use App\Modules\Admin\Domain\ValueObjects\Money;
use App\Modules\Admin\Domain\ValueObjects\PaymentResult;
use App\Modules\Admin\Domain\ValueObjects\RefundResult;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ManualPaymentProvider implements PaymentProviderInterface
{
    private const SUPPORTED_COUNTRIES = ['TN', 'DZ', 'MA', 'LY', 'EG'];

    public function getCode(): string
    {
        return 'manual';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::SUPPORTED_COUNTRIES, true);
    }

    public function supportsRecurring(): bool
    {
        return false; // Manual payments cannot auto-renew
    }

    public function createCustomer(Tenant $tenant): string
    {
        // No external customer ID needed for manual payments
        return 'manual_' . $tenant->id;
    }

    public function createSubscription(
        Tenant $tenant,
        Plan $plan,
        ?string $paymentMethodId = null
    ): Subscription {
        // Create subscription in pending state awaiting payment
        return Subscription::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending_payment',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'provider' => $this->getCode(),
        ]);
    }

    public function processPayment(Invoice $invoice): PaymentResult
    {
        // Manual provider cannot auto-process payments
        // Return pending status requiring admin action
        return new PaymentResult(
            success: false,
            status: PaymentStatus::PendingManualReview,
            message: 'Payment requires manual processing. Invoice sent to customer.',
            requiresAction: true,
            actionType: 'awaiting_bank_transfer',
        );
    }

    public function recordManualPayment(
        Invoice $invoice,
        Money $amount,
        string $paymentMethod,
        ?string $reference = null,
        ?string $notes = null
    ): PaymentResult {
        // Validate payment amount
        if ($amount->lessThan($invoice->total)) {
            return new PaymentResult(
                success: false,
                status: PaymentStatus::Failed,
                message: 'Payment amount is less than invoice total.',
            );
        }

        // Create payment record
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'provider' => $this->getCode(),
            'amount' => $amount->amount,
            'currency' => $amount->currency,
            'status' => PaymentStatus::Succeeded,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'notes' => $notes,
            'recorded_by' => auth()->id(),
            'recorded_at' => now(),
        ]);

        // Update invoice status
        $invoice->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

        // Activate subscription if exists
        if ($invoice->subscription) {
            $invoice->subscription->update([
                'status' => 'active',
            ]);
        }

        return new PaymentResult(
            success: true,
            status: PaymentStatus::Succeeded,
            paymentId: $payment->id,
            message: 'Payment recorded successfully.',
        );
    }

    public function cancelSubscription(
        Subscription $subscription,
        bool $immediate = false
    ): void {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => $immediate ? now() : $subscription->current_period_end,
        ]);
    }

    public function refund(Payment $payment, Money $amount): RefundResult
    {
        // Manual refunds are recorded but actual refund is done externally
        return new RefundResult(
            success: true,
            refundId: Str::uuid()->toString(),
            message: 'Refund recorded. Please process bank transfer manually.',
            requiresManualAction: true,
        );
    }

    public function getInvoices(Tenant $tenant): Collection
    {
        return Invoice::where('tenant_id', $tenant->id)
            ->where('provider', $this->getCode())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getCheckoutUrl(Tenant $tenant, Plan $plan): ?string
    {
        return null; // No online checkout for manual payments
    }

    public function getBillingPortalUrl(Tenant $tenant): ?string
    {
        return null; // No self-service portal for manual payments
    }

    public function handleWebhook(array $payload, string $signature): void
    {
        // No webhooks for manual payments
        throw new \BadMethodCallException('Manual provider does not support webhooks');
    }
}
```

### Bank Account Configuration

```php
// config/payment.php

return [
    'manual' => [
        'bank_accounts' => [
            'TN' => [
                'bank_name' => 'Banque de Tunisie',
                'account_name' => 'AutoERP SARL',
                'iban' => 'TN59 0000 0000 0000 0000 0000',
                'bic' => 'BTTNTNTT',
                'rib' => '00 000 0000000000000 00',
            ],
            'FR' => [
                'bank_name' => 'BNP Paribas',
                'account_name' => 'AutoERP SAS',
                'iban' => 'FR76 0000 0000 0000 0000 0000 000',
                'bic' => 'BNPAFRPP',
            ],
        ],
        'payment_terms_days' => 15,
        'reminder_days' => [7, 3, 1, 0], // Days before due date
        'overdue_reminder_days' => [1, 3, 7, 14], // Days after due date
    ],
];
```

### Invoice PDF Template

The invoice PDF should include:

1. **Header**
   - Company logo and details
   - Invoice number and date
   - Due date

2. **Customer Details**
   - Tenant name and address
   - Tax ID if applicable

3. **Line Items**
   - Plan description
   - Period covered
   - Unit price and quantity
   - Tax breakdown

4. **Payment Instructions**
   - Bank account details
   - Payment reference (must include invoice number)
   - QR code for mobile banking (optional)

5. **Footer**
   - Terms and conditions
   - Support contact

---

## Provider: Stripe (Europe)

### Overview

For European markets, Stripe provides:

- **Card payments** (Visa, Mastercard, Amex)
- **SEPA Direct Debit** (bank account)
- **Local payment methods** (iDEAL, Bancontact, etc.)
- **Automated recurring billing**
- **Tax calculation** (Stripe Tax)

### Workflow

```
┌──────────────────────────────────────────────────────────────────────┐
│                         STRIPE PAYMENT FLOW                          │
└──────────────────────────────────────────────────────────────────────┘

1. SUBSCRIPTION SETUP
   ┌─────────────┐
   │   Tenant    │──▶ Clicks "Upgrade to Pro"
   └─────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Redirect to Stripe Checkout       │
   │  - Pre-filled customer info        │
   │  - Plan details                    │
   │  - Tax calculated                  │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Tenant enters payment method      │
   │  - Card details                    │
   │  - Or selects SEPA                 │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Stripe processes payment          │
   │  Creates subscription              │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Webhook: checkout.session.completed │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  System activates subscription     │
   │  Sends confirmation email          │
   └─────────────────────────────────────┘

2. RECURRING BILLING (Automatic)
   ┌─────────────────────────────────────┐
   │  Stripe charges subscription       │
   │  on renewal date                   │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  Webhook: invoice.payment_succeeded │
   │  or invoice.payment_failed         │
   └─────────────────────────────────────┘
          │
          ▼
   ┌─────────────────────────────────────┐
   │  System updates subscription status │
   │  Triggers dunning if failed        │
   └─────────────────────────────────────┘
```

### Implementation

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Providers;

use App\Modules\Admin\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Admin\Domain\Invoice;
use App\Modules\Admin\Domain\InvoiceStatus;
use App\Modules\Admin\Domain\Payment;
use App\Modules\Admin\Domain\PaymentStatus;
use App\Modules\Admin\Domain\Plan;
use App\Modules\Admin\Domain\Subscription;
use App\Modules\Admin\Domain\ValueObjects\Money;
use App\Modules\Admin\Domain\ValueObjects\PaymentResult;
use App\Modules\Admin\Domain\ValueObjects\RefundResult;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Stripe\StripeClient;

final class StripePaymentProvider implements PaymentProviderInterface
{
    private const SUPPORTED_COUNTRIES = [
        'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'PT', 'IE',
        'FI', 'GR', 'LU', 'GB', 'CH', 'SE', 'DK', 'NO', 'PL',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
    ) {}

    public function getCode(): string
    {
        return 'stripe';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::SUPPORTED_COUNTRIES, true);
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function createCustomer(Tenant $tenant): string
    {
        $customer = $this->stripe->customers->create([
            'email' => $tenant->email ?? $tenant->owner?->email,
            'name' => $tenant->legal_name ?? $tenant->name,
            'metadata' => [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
            ],
            'address' => $tenant->address ? [
                'line1' => $tenant->address['line1'] ?? '',
                'line2' => $tenant->address['line2'] ?? '',
                'city' => $tenant->address['city'] ?? '',
                'postal_code' => $tenant->address['postal_code'] ?? '',
                'country' => $tenant->country_code,
            ] : null,
            'tax_id_data' => $tenant->tax_id ? [
                ['type' => $this->getTaxIdType($tenant->country_code), 'value' => $tenant->tax_id],
            ] : [],
        ]);

        return $customer->id;
    }

    public function createSubscription(
        Tenant $tenant,
        Plan $plan,
        ?string $paymentMethodId = null
    ): Subscription {
        // Ensure customer exists
        $customerId = $tenant->paymentConfig?->external_customer_id
            ?? $this->createCustomer($tenant);

        $stripeSubscription = $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [
                ['price' => $plan->stripe_price_id],
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
            ],
            'automatic_tax' => ['enabled' => true],
        ]);

        return Subscription::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $this->mapStripeStatus($stripeSubscription->status),
            'provider' => $this->getCode(),
            'external_id' => $stripeSubscription->id,
            'current_period_start' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end),
        ]);
    }

    public function getCheckoutUrl(Tenant $tenant, Plan $plan): ?string
    {
        $customerId = $tenant->paymentConfig?->external_customer_id
            ?? $this->createCustomer($tenant);

        $session = $this->stripe->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $plan->stripe_price_id,
                    'quantity' => 1,
                ],
            ],
            'success_url' => config('app.frontend_url') . '/settings/subscription?success=true',
            'cancel_url' => config('app.frontend_url') . '/settings/subscription?cancelled=true',
            'automatic_tax' => ['enabled' => true],
            'tax_id_collection' => ['enabled' => true],
            'metadata' => [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
            ],
        ]);

        return $session->url;
    }

    public function getBillingPortalUrl(Tenant $tenant): ?string
    {
        $customerId = $tenant->paymentConfig?->external_customer_id;

        if ($customerId === null) {
            return null;
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => config('app.frontend_url') . '/settings/subscription',
        ]);

        return $session->url;
    }

    public function cancelSubscription(
        Subscription $subscription,
        bool $immediate = false
    ): void {
        if ($subscription->external_id === null) {
            throw new \InvalidArgumentException('Subscription has no Stripe ID');
        }

        if ($immediate) {
            $this->stripe->subscriptions->cancel($subscription->external_id);
        } else {
            $this->stripe->subscriptions->update($subscription->external_id, [
                'cancel_at_period_end' => true,
            ]);
        }

        $subscription->update([
            'status' => $immediate ? 'cancelled' : 'cancelling',
            'cancelled_at' => now(),
            'ends_at' => $immediate ? now() : $subscription->current_period_end,
        ]);
    }

    public function processPayment(Invoice $invoice): PaymentResult
    {
        // For Stripe, payments are handled automatically via subscriptions
        // This method is for one-time charges if needed
        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => (int) ($invoice->total * 100),
            'currency' => strtolower($invoice->currency),
            'customer' => $invoice->tenant->paymentConfig?->external_customer_id,
            'metadata' => [
                'invoice_id' => $invoice->id,
                'tenant_id' => $invoice->tenant_id,
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return new PaymentResult(
            success: $paymentIntent->status === 'succeeded',
            status: $this->mapPaymentIntentStatus($paymentIntent->status),
            paymentId: $paymentIntent->id,
            clientSecret: $paymentIntent->client_secret,
        );
    }

    public function recordManualPayment(
        Invoice $invoice,
        Money $amount,
        string $paymentMethod,
        ?string $reference = null,
        ?string $notes = null
    ): PaymentResult {
        // Even with Stripe, sometimes manual recording is needed
        // (e.g., bank transfer for large enterprise deals)
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'provider' => $this->getCode(),
            'amount' => $amount->amount,
            'currency' => $amount->currency,
            'status' => PaymentStatus::Succeeded,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'notes' => $notes,
            'recorded_by' => auth()->id(),
            'recorded_at' => now(),
        ]);

        // Sync with Stripe if there's an invoice
        if ($invoice->external_id) {
            $this->stripe->invoices->pay($invoice->external_id, [
                'paid_out_of_band' => true,
            ]);
        }

        $invoice->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

        return new PaymentResult(
            success: true,
            status: PaymentStatus::Succeeded,
            paymentId: $payment->id,
        );
    }

    public function refund(Payment $payment, Money $amount): RefundResult
    {
        if ($payment->external_id === null) {
            return new RefundResult(
                success: false,
                message: 'Cannot refund: no Stripe payment ID',
            );
        }

        $refund = $this->stripe->refunds->create([
            'payment_intent' => $payment->external_id,
            'amount' => (int) ($amount->amount * 100),
            'metadata' => [
                'payment_id' => $payment->id,
                'tenant_id' => $payment->tenant_id,
            ],
        ]);

        return new RefundResult(
            success: $refund->status === 'succeeded',
            refundId: $refund->id,
            message: $refund->status === 'succeeded'
                ? 'Refund processed successfully'
                : 'Refund is pending',
        );
    }

    public function getInvoices(Tenant $tenant): Collection
    {
        $customerId = $tenant->paymentConfig?->external_customer_id;

        if ($customerId === null) {
            return collect();
        }

        $stripeInvoices = $this->stripe->invoices->all([
            'customer' => $customerId,
            'limit' => 100,
        ]);

        return collect($stripeInvoices->data)->map(function ($stripeInvoice) use ($tenant) {
            return Invoice::updateOrCreate(
                ['external_id' => $stripeInvoice->id],
                [
                    'tenant_id' => $tenant->id,
                    'invoice_number' => $stripeInvoice->number,
                    'status' => $this->mapInvoiceStatus($stripeInvoice->status),
                    'currency' => strtoupper($stripeInvoice->currency),
                    'subtotal' => $stripeInvoice->subtotal / 100,
                    'tax_amount' => ($stripeInvoice->tax ?? 0) / 100,
                    'total' => $stripeInvoice->total / 100,
                    'due_date' => $stripeInvoice->due_date
                        ? \Carbon\Carbon::createFromTimestamp($stripeInvoice->due_date)
                        : null,
                    'paid_at' => $stripeInvoice->status_transitions?->paid_at
                        ? \Carbon\Carbon::createFromTimestamp($stripeInvoice->status_transitions->paid_at)
                        : null,
                    'pdf_url' => $stripeInvoice->invoice_pdf,
                    'provider' => $this->getCode(),
                ]
            );
        });
    }

    public function handleWebhook(array $payload, string $signature): void
    {
        $event = \Stripe\Webhook::constructEvent(
            json_encode($payload),
            $signature,
            $this->webhookSecret
        );

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'customer.subscription.created' => $this->handleSubscriptionCreated($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
            'invoice.payment_succeeded' => $this->handleInvoicePaid($event->data->object),
            'invoice.payment_failed' => $this->handleInvoiceFailed($event->data->object),
            default => null, // Ignore unknown events
        };
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $tenantId = $session->metadata->tenant_id ?? null;

        if ($tenantId === null) {
            return;
        }

        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return;
        }

        // Update tenant's payment config with customer ID
        $tenant->paymentConfig()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'provider_code' => $this->getCode(),
                'external_customer_id' => $session->customer,
            ]
        );
    }

    private function handleSubscriptionCreated(object $subscription): void
    {
        // Sync subscription to local database
        $tenantId = $subscription->metadata->tenant_id ?? null;

        if ($tenantId === null) {
            return;
        }

        Subscription::updateOrCreate(
            ['external_id' => $subscription->id],
            [
                'tenant_id' => $tenantId,
                'status' => $this->mapStripeStatus($subscription->status),
                'current_period_start' => \Carbon\Carbon::createFromTimestamp($subscription->current_period_start),
                'current_period_end' => \Carbon\Carbon::createFromTimestamp($subscription->current_period_end),
                'provider' => $this->getCode(),
            ]
        );
    }

    private function handleSubscriptionUpdated(object $subscription): void
    {
        $localSubscription = Subscription::where('external_id', $subscription->id)->first();

        if ($localSubscription === null) {
            return;
        }

        $localSubscription->update([
            'status' => $this->mapStripeStatus($subscription->status),
            'current_period_start' => \Carbon\Carbon::createFromTimestamp($subscription->current_period_start),
            'current_period_end' => \Carbon\Carbon::createFromTimestamp($subscription->current_period_end),
        ]);
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $localSubscription = Subscription::where('external_id', $subscription->id)->first();

        if ($localSubscription === null) {
            return;
        }

        $localSubscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now(),
        ]);

        // Update tenant status
        $localSubscription->tenant->update([
            'status' => 'expired',
        ]);
    }

    private function handleInvoicePaid(object $invoice): void
    {
        Invoice::where('external_id', $invoice->id)->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    private function handleInvoiceFailed(object $invoice): void
    {
        Invoice::where('external_id', $invoice->id)->update([
            'status' => InvoiceStatus::Failed,
        ]);

        // Trigger dunning process
        // DunningJob::dispatch($invoice->id);
    }

    private function mapStripeStatus(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled' => 'cancelled',
            'incomplete' => 'pending',
            'incomplete_expired' => 'expired',
            'trialing' => 'trial',
            default => $status,
        };
    }

    private function mapPaymentIntentStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'succeeded' => PaymentStatus::Succeeded,
            'processing' => PaymentStatus::Processing,
            'requires_payment_method' => PaymentStatus::Failed,
            'requires_action' => PaymentStatus::RequiresAction,
            default => PaymentStatus::Pending,
        };
    }

    private function mapInvoiceStatus(string $status): InvoiceStatus
    {
        return match ($status) {
            'paid' => InvoiceStatus::Paid,
            'open' => InvoiceStatus::Pending,
            'draft' => InvoiceStatus::Draft,
            'uncollectible' => InvoiceStatus::Failed,
            'void' => InvoiceStatus::Void,
            default => InvoiceStatus::Pending,
        };
    }

    private function getTaxIdType(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'FR' => 'eu_vat',
            'DE' => 'eu_vat',
            'IT' => 'eu_vat',
            'ES' => 'eu_vat',
            'GB' => 'gb_vat',
            'CH' => 'ch_vat',
            default => 'eu_vat',
        };
    }
}
```

### Webhook Endpoint

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation\Webhooks;

use App\Modules\Admin\Application\Services\PaymentProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentProviderResolver $providerResolver,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->all();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $provider = $this->providerResolver->get('stripe');
            $provider->handleWebhook($payload, $signature);

            return response('', 200);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        } catch (\Exception $e) {
            report($e);
            return response('Webhook error', 500);
        }
    }
}
```

---

## Super Admin Billing Interface

### Required Features

1. **Invoice Management**
   - List all invoices (filterable by tenant, status, date)
   - View invoice details
   - Download invoice PDF
   - Mark manual payment as received
   - Void/cancel invoice
   - Resend invoice email

2. **Payment Management**
   - List all payments
   - View payment details
   - Record manual payment
   - Issue refund (partial or full)

3. **Subscription Management**
   - View all subscriptions
   - Extend trial
   - Change plan
   - Cancel subscription
   - Pause subscription (future)

4. **Revenue Dashboard**
   - MRR breakdown by plan
   - Payment method distribution
   - Outstanding invoices
   - Failed payment queue

### API Endpoints

```
# Invoices
GET    /api/v1/admin/invoices                    # List all invoices
GET    /api/v1/admin/invoices/{id}               # Invoice details
POST   /api/v1/admin/invoices/{id}/mark-paid     # Record payment
POST   /api/v1/admin/invoices/{id}/void          # Void invoice
POST   /api/v1/admin/invoices/{id}/resend        # Resend email
GET    /api/v1/admin/tenants/{id}/invoices       # Tenant's invoices

# Payments
GET    /api/v1/admin/payments                    # List all payments
GET    /api/v1/admin/payments/{id}               # Payment details
POST   /api/v1/admin/payments/{id}/refund        # Issue refund

# Subscriptions
GET    /api/v1/admin/subscriptions               # List all
GET    /api/v1/admin/subscriptions/{id}          # Details
POST   /api/v1/admin/subscriptions/{id}/cancel   # Cancel

# Revenue
GET    /api/v1/admin/metrics/revenue             # Revenue metrics
GET    /api/v1/admin/metrics/mrr                 # MRR breakdown
```

---

## Configuration

### Environment Variables

```env
# Payment Provider Selection
PAYMENT_DEFAULT_PROVIDER=manual  # 'manual' or 'stripe'

# Stripe Configuration
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# Manual Payment Configuration
PAYMENT_MANUAL_BANK_ACCOUNT_TN="Banque de Tunisie|TN59 0000 0000 0000 0000 0000"
PAYMENT_MANUAL_TERMS_DAYS=15

# Tax Configuration
TAX_ENABLED=true
TAX_PROVIDER=stripe  # or 'manual'
TAX_RATE_TN=19
TAX_RATE_FR=20
```

### Service Provider Registration

```php
// app/Providers/PaymentServiceProvider.php

public function register(): void
{
    $this->app->singleton(ManualPaymentProvider::class);

    $this->app->singleton(StripePaymentProvider::class, function ($app) {
        return new StripePaymentProvider(
            new StripeClient(config('services.stripe.secret')),
            config('services.stripe.webhook_secret'),
        );
    });

    $this->app->singleton(PaymentProviderResolver::class, function ($app) {
        return new PaymentProviderResolver(
            providers: [
                'manual' => $app->make(ManualPaymentProvider::class),
                'stripe' => $app->make(StripePaymentProvider::class),
            ],
            defaultProvider: config('payment.default_provider', 'manual'),
        );
    });
}
```

---

## Testing

### Manual Provider Tests

```php
#[Test]
public function it_generates_proforma_invoice(): void
{
    $tenant = Tenant::factory()->create(['country_code' => 'TN']);
    $plan = Plan::factory()->create(['price' => 99.00]);

    $provider = new ManualPaymentProvider();
    $subscription = $provider->createSubscription($tenant, $plan);

    expect($subscription->status)->toBe('pending_payment');
}

#[Test]
public function it_records_manual_payment(): void
{
    $invoice = Invoice::factory()->create(['status' => 'pending']);

    $provider = new ManualPaymentProvider();
    $result = $provider->recordManualPayment(
        $invoice,
        new Money($invoice->total, $invoice->currency),
        'bank_transfer',
        'REF-12345'
    );

    expect($result->success)->toBeTrue();
    expect($invoice->fresh()->status)->toBe('paid');
}
```

### Stripe Provider Tests

```php
#[Test]
public function it_creates_stripe_customer(): void
{
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'cus_xxx',
            'email' => 'test@example.com',
        ]),
    ]);

    $tenant = Tenant::factory()->create();
    $provider = app(StripePaymentProvider::class);

    $customerId = $provider->createCustomer($tenant);

    expect($customerId)->toBe('cus_xxx');
}

#[Test]
public function it_handles_payment_succeeded_webhook(): void
{
    $invoice = Invoice::factory()->create([
        'external_id' => 'in_xxx',
        'status' => 'pending',
    ]);

    $provider = app(StripePaymentProvider::class);
    $provider->handleWebhook([
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['id' => 'in_xxx']],
    ], 'valid_signature');

    expect($invoice->fresh()->status)->toBe('paid');
}
```

---

## Migration Path

### Phase 1: Manual Only (Current)
- Tunisia market launch
- Bank transfer payments
- Super admin reconciliation

### Phase 2: Add Stripe (EU Launch)
- France market entry
- Stripe integration
- Automated billing for new EU tenants

### Phase 3: Migration
- Offer existing Tunisia tenants Stripe option
- Gradually move to automated where possible
- Keep manual as fallback

---

*Document Version: 1.0*
*Created: December 2025*
