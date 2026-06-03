<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Services;

use App\Models\CountryTaxRate;
use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\InvoiceItem;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Service for managing billing invoices.
 */
final class InvoiceService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Create an invoice for a subscription payment.
     */
    public function createSubscriptionInvoice(TenantSubscription $subscription): Invoice
    {
        return DB::transaction(function () use ($subscription): Invoice {
            $tenant = $subscription->tenant;
            $plan = $subscription->plan;

            if ($tenant === null || $plan === null) {
                throw new \RuntimeException('Subscription must have a tenant and plan to generate an invoice');
            }

            // Generate invoice number
            $number = $this->generateInvoiceNumber();

            // Calculate amounts
            $price = (float) ($subscription->price ?? $plan->price_monthly);
            $taxRate = $this->getTaxRate($tenant);
            $taxAmount = $price * ($taxRate / 100);
            $total = $price + $taxAmount;

            // Create invoice
            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'number' => $number,
                'status' => InvoiceStatus::Pending,
                'subtotal' => $price,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'total' => $total,
                'amount_paid' => 0,
                'amount_due' => $total,
                'currency' => $subscription->currency ?? 'EUR',
                'tax_rate' => $taxRate,
                'billing_address' => $this->getBillingAddress($tenant),
                'billing_email' => $tenant->email ?? '',
                'billing_name' => $tenant->name,
                'invoice_date' => now(),
                'due_date' => now()->addDays(14),
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'footer_text' => $this->getFooterText(),
            ]);

            // Add line item
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => "{$plan->name} - {$subscription->billing_cycle}",
                'long_description' => $plan->description,
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'discount_percent' => 0,
                'discount_amount' => 0,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'reference_type' => 'plan',
                'reference_id' => $plan->id,
                'sort_order' => 0,
            ]);

            return $invoice;
        });
    }

    /**
     * Create a manual invoice (not linked to subscription).
     *
     * @param  array<array{description: string, amount: float|string, quantity?: float|int}>  $items
     */
    public function createManualInvoice(
        Tenant $tenant,
        array $items,
        ?string $notes = null,
        ?\DateTimeInterface $dueDate = null,
    ): Invoice {
        return DB::transaction(function () use ($tenant, $items, $notes, $dueDate): Invoice {
            $taxRate = $this->getTaxRate($tenant);

            // Billing invoices are always EUR; use the resolver for forward-compatibility.
            $currency = 'EUR';
            $scale = $this->scaleResolver->getScale($currency);
            $interScale = $scale + 1;

            // Accumulate subtotal and tax using bcmath to avoid float precision drift.
            /** @var numeric-string $subtotal */
            $subtotal = '0';
            /** @var numeric-string $taxAmount */
            $taxAmount = '0';

            foreach ($items as $item) {
                /** @var numeric-string $quantity */
                $quantity = (string) ($item['quantity'] ?? 1);
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $item['amount'];
                /** @var numeric-string $itemAmount */
                $itemAmount = bcmul($unitPrice, $quantity, $interScale);
                $subtotal = bcadd($subtotal, $itemAmount, $interScale);
                // Tax accumulation at intermediate scale to defer rounding.
                /** @var numeric-string $taxRateFraction */
                $taxRateFraction = bcdiv((string) $taxRate, '100', $interScale + 2);
                $taxAmount = bcadd($taxAmount, bcmul($itemAmount, $taxRateFraction, $interScale), $interScale);
            }

            /** @var numeric-string $total */
            $total = bcadd($subtotal, $taxAmount, $interScale);

            // Create invoice
            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => null,
                'number' => $this->generateInvoiceNumber(),
                'status' => InvoiceStatus::Pending,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => '0',
                'total' => $total,
                'amount_paid' => '0',
                'amount_due' => $total,
                'currency' => $currency,
                'tax_rate' => $taxRate,
                'billing_address' => $this->getBillingAddress($tenant),
                'billing_email' => $tenant->email ?? '',
                'billing_name' => $tenant->name,
                'invoice_date' => now(),
                'due_date' => $dueDate ?? now()->addDays(14),
                'notes' => $notes,
                'footer_text' => $this->getFooterText(),
            ]);

            // Add line items
            foreach ($items as $index => $item) {
                /** @var numeric-string $quantity */
                $quantity = (string) ($item['quantity'] ?? 1);
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $item['amount'];
                /** @var numeric-string $itemAmount */
                $itemAmount = bcmul($unitPrice, $quantity, $interScale);
                /** @var numeric-string $taxRateFraction */
                $taxRateFraction = bcdiv((string) $taxRate, '100', $interScale + 2);
                /** @var numeric-string $itemTax */
                $itemTax = bcmul($itemAmount, $taxRateFraction, $interScale);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $itemAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'discount_percent' => '0',
                    'discount_amount' => '0',
                    'sort_order' => $index,
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Generate PDF for an invoice.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['tenant', 'items', 'subscription.plan']);

        $data = [
            'invoice' => $invoice,
            'company' => $this->getCompanyDetails(),
        ];

        $pdf = Pdf::loadView('billing.invoice', $data);
        $pdf->setPaper('a4');

        return $pdf->output();
    }

    /**
     * Generate and store PDF for an invoice.
     */
    public function generateAndStorePdf(Invoice $invoice): string
    {
        $pdfContent = $this->generatePdf($invoice);

        $path = "invoices/{$invoice->tenant_id}/{$invoice->number}.pdf";
        Storage::disk('private')->put($path, $pdfContent);

        $invoice->update(['pdf_path' => $path]);

        return $path;
    }

    /**
     * Get PDF path or generate if not exists.
     */
    public function getPdfPath(Invoice $invoice): string
    {
        if ($invoice->pdf_path && Storage::disk('private')->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return $this->generateAndStorePdf($invoice);
    }

    /**
     * Mark invoice as sent.
     */
    public function markAsSent(Invoice $invoice): void
    {
        $invoice->markAsSent();
    }

    /**
     * Cancel an invoice.
     */
    public function cancel(Invoice $invoice): void
    {
        if ($invoice->status->isFinal()) {
            throw new \InvalidArgumentException('Cannot cancel a finalized invoice');
        }

        $invoice->update([
            'status' => InvoiceStatus::Cancelled,
        ]);
    }

    /**
     * Get invoices for a tenant.
     *
     * @return Collection<int, Invoice>
     */
    public function getTenantInvoices(string $tenantId): Collection
    {
        return Invoice::where('tenant_id', $tenantId)
            ->orderBy('invoice_date', 'desc')
            ->get();
    }

    /**
     * Get unpaid invoices for a tenant.
     *
     * @return Collection<int, Invoice>
     */
    public function getUnpaidInvoices(string $tenantId): Collection
    {
        return Invoice::where('tenant_id', $tenantId)
            ->whereIn('status', [
                InvoiceStatus::Pending,
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Overdue,
            ])
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Get overdue invoices.
     *
     * @return Collection<int, Invoice>
     */
    public function getOverdueInvoices(): Collection
    {
        return Invoice::whereIn('status', [
            InvoiceStatus::Pending,
            InvoiceStatus::Sent,
            InvoiceStatus::PartiallyPaid,
        ])
            ->where('due_date', '<', now())
            ->orderBy('due_date', 'asc')
            ->get();
    }

    /**
     * Update overdue invoices status.
     */
    public function markOverdueInvoices(): int
    {
        return Invoice::whereIn('status', [
            InvoiceStatus::Pending,
            InvoiceStatus::Sent,
            InvoiceStatus::PartiallyPaid,
        ])
            ->where('due_date', '<', now())
            ->update(['status' => InvoiceStatus::Overdue]);
    }

    /**
     * Generate a unique invoice number.
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = config('billing.invoice_prefix', 'INV');
        $year = now()->format('Y');
        $month = now()->format('m');

        // Get next sequence number for this month
        $lastInvoice = Invoice::where('number', 'like', "{$prefix}-{$year}{$month}%")
            ->orderBy('number', 'desc')
            ->first();

        if ($lastInvoice) {
            // Extract sequence and increment
            $lastSequence = (int) substr($lastInvoice->number, -4);
            $sequence = str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $sequence = '0001';
        }

        return "{$prefix}-{$year}{$month}-{$sequence}";
    }

    /**
     * Get default tax rate for tenant based on country.
     *
     * Looks up the default tax rate from country_tax_rates table.
     * Falls back to config value if no rate is found.
     */
    private function getTaxRate(Tenant $tenant): float
    {
        $countryCode = $tenant->country_code ?? config('billing.default_country', 'FR');

        $defaultRate = CountryTaxRate::where('country_code', $countryCode)
            ->where('is_default', true)
            ->where('is_active', true)
            ->value('rate');

        if ($defaultRate !== null) {
            return (float) $defaultRate;
        }

        return (float) config('billing.default_tax_rate', 20.0);
    }

    /**
     * Get billing address from tenant.
     *
     * @return array<string, string|null>
     */
    private function getBillingAddress(Tenant $tenant): array
    {
        $address = $tenant->address;

        return [
            'line1' => $address['line1'] ?? $address['street'] ?? '',
            'line2' => $address['line2'] ?? '',
            'city' => $address['city'] ?? '',
            'postal_code' => $address['postal_code'] ?? '',
            'country' => $tenant->country_code ?? '',
        ];
    }

    /**
     * Get company details for invoice.
     *
     * @return array<string, string>
     */
    private function getCompanyDetails(): array
    {
        return [
            'name' => config('billing.company.name', 'Mecanospex'),
            'address' => config('billing.company.address', ''),
            'city' => config('billing.company.city', ''),
            'postal_code' => config('billing.company.postal_code', ''),
            'country' => config('billing.company.country', ''),
            'phone' => config('billing.company.phone', ''),
            'email' => config('billing.company.email', ''),
            'website' => config('billing.company.website', ''),
            'vat_number' => config('billing.company.vat_number', ''),
            'registration' => config('billing.company.registration', ''),
            'logo_path' => config('billing.company.logo_path', ''),
        ];
    }

    /**
     * Get invoice footer text.
     */
    private function getFooterText(): string
    {
        return config(
            'billing.invoice_footer',
            'Thank you for your business. Payment is due within 14 days.'
        );
    }
}
