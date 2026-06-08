<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Conversion;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\DocumentLineDiscountStrippedAtConversion;
use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Conversion auto-strip behavior — spec §7 (Document-conversion behaviour).
 *
 * Exercises both QuoteToSalesOrderConverter and SalesOrderToInvoiceConverter
 * to confirm the StripSubToleranceDiscountsService is invoked during the
 * convert() transaction, sub-tolerance line discounts are zeroed, and an
 * immutable {@see DocumentLineDiscountStrippedAtConversion} event is
 * dispatched per stripped line for audit-trail traceability.
 *
 * Above-tolerance discounts must pass through unchanged with no event
 * dispatched — the rule is strictly a sub-tolerance abuse check.
 */
final class ToleranceDiscountStripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        CountryPaymentSettings::firstOrCreate(
            ['country_code' => 'FR'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0050',
                'max_payment_tolerance_amount' => '0.500',
            ],
        );

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Bind CompanyContext so CurrencyScaleResolver has context during conversion
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_quote_to_sales_order_strips_sub_tolerance_line_discount(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        $quote = $this->makeConfirmedQuote(lineDiscountAmount: '0.20');

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $order = $converter->convert($quote);

        // The order's first line must have a stripped discount (zeroed, not null —
        // spec §7) so downstream consumers that check `!== null` keep working.
        $orderLine = $order->lines()->first();
        $this->assertNotNull($orderLine);
        $this->assertNotNull($orderLine->discount_amount);
        $this->assertSame(0, bccomp($orderLine->discount_amount, '0', 4));
        $this->assertNotNull($orderLine->discount_percent);
        $this->assertSame(0, bccomp($orderLine->discount_percent, '0', 4));

        // Audit event dispatched once with correct shape.
        Event::assertDispatched(DocumentLineDiscountStrippedAtConversion::class, function ($event) use ($quote, $order, $orderLine) {
            return $event->lineId === (string) $orderLine->id
                && $event->sourceDocumentId === (string) $quote->id
                && $event->targetDocumentId === (string) $order->id
                && bccomp($event->originalDiscountAmount, '0.20', 4) === 0
                && bccomp($event->toleranceMargin, '0.50', 4) === 0
                && $event->sourceType === 'quote'
                && $event->targetType === 'sales_order';
        });
    }

    public function test_quote_to_sales_order_preserves_above_tolerance_discount(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        $quote = $this->makeConfirmedQuote(lineDiscountAmount: '5.00');

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $order = $converter->convert($quote);

        $orderLine = $order->lines()->first();
        $this->assertNotNull($orderLine);
        $this->assertNotNull($orderLine->discount_amount);
        $this->assertSame(0, bccomp($orderLine->discount_amount, '5.00', 4));

        Event::assertNotDispatched(DocumentLineDiscountStrippedAtConversion::class);
    }

    public function test_sales_order_to_invoice_strips_sub_tolerance_line_discount(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        // Services-only order (no product_id on the line) → SalesOrderToInvoiceConverter
        // skips the delivery-note compliance branch and exercises the strip path
        // without needing a Location/FEFO setup.
        $order = $this->makeConfirmedOrder(lineDiscountAmount: '0.20');

        $converter = $this->app->make(SalesOrderToInvoiceConverter::class);
        $invoice = $converter->convert($order);

        $invoiceLine = $invoice->lines()->first();
        $this->assertNotNull($invoiceLine);
        $this->assertNotNull($invoiceLine->discount_amount);
        $this->assertSame(0, bccomp($invoiceLine->discount_amount, '0', 4));

        Event::assertDispatched(DocumentLineDiscountStrippedAtConversion::class, function ($event) use ($order, $invoice) {
            return $event->sourceDocumentId === (string) $order->id
                && $event->targetDocumentId === (string) $invoice->id
                && bccomp($event->originalDiscountAmount, '0.20', 4) === 0
                && $event->sourceType === 'sales_order'
                && $event->targetType === 'invoice';
        });
    }

    public function test_strip_uses_target_document_id_as_aggregate_root(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        $quote = $this->makeConfirmedQuote(lineDiscountAmount: '0.20');
        $order = $this->app->make(QuoteToSalesOrderConverter::class)->convert($quote);

        Event::assertDispatched(DocumentLineDiscountStrippedAtConversion::class, function ($event) use ($order): bool {
            return $event->aggregateRootUuid() === (string) $order->id;
        });
    }

    public function test_strip_recomputes_line_total_so_residual_flows_through(): void
    {
        // Seed a quote where line_total bakes the discount in (qty*unit_price - discount).
        // Per spec §7, the stripped discount must flow through as an amount due —
        // so after the strip line_total must increase to qty*unit_price.
        $quote = $this->makeConfirmedQuote(
            lineDiscountAmount: '0.20',
            lineTotalOverride: '99.80', // 1.00 * 100.00 - 0.20
        );

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $order = $converter->convert($quote);

        $orderLine = $order->lines()->first();
        $this->assertNotNull($orderLine);

        // line_total now reflects the gross (no discount applied).
        $this->assertSame(0, bccomp((string) $orderLine->line_total, '100.0000', 4));

        // Document totals reflect the recomputed lines (recalculateTotals
        // must run after the strip, otherwise the SO carries stale source totals).
        $this->assertSame(0, bccomp((string) $order->subtotal, '100.0000', 4));
        // tax = 100 * 20% = 20.00 → total = 120.00
        $this->assertSame(0, bccomp((string) $order->tax_amount, '20.0000', 4));
        $this->assertSame(0, bccomp((string) $order->total, '120.0000', 4));
    }

    public function test_listener_persists_audit_event_on_strip(): void
    {
        // No Event::fake here — we want the real subscriber to run end-to-end.
        $quote = $this->makeConfirmedQuote(lineDiscountAmount: '0.20');

        $order = $this->app->make(QuoteToSalesOrderConverter::class)->convert($quote);

        $event = AuditEvent::query()
            ->where('event_type', 'document.discount.stripped_on_conversion')
            ->where('aggregate_id', (string) $order->id)
            ->first();

        $this->assertNotNull(
            $event,
            'Expected an audit event for the conversion strip but found none.',
        );
        // The model exposes both a hydrated public property and the underlying
        // raw attribute; check the raw attribute to avoid coupling to model
        // hydration semantics.
        $this->assertSame('Document', $event->getAttribute('aggregate_type'));
    }

    private function makeConfirmedQuote(
        string $lineDiscountAmount,
        ?string $lineTotalOverride = null,
    ): Document {
        /** @phpstan-ignore-next-line argument.type */
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Quote),
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-TEST-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $quote->id,
            'line_number' => 1,
            'description' => 'Service line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'discount_amount' => $lineDiscountAmount,
            'tax_rate' => '20.00',
            'line_total' => $lineTotalOverride ?? '100.00',
        ]);

        $fresh = $quote->fresh(['lines']);
        $this->assertNotNull($fresh, 'Failed to refresh seeded quote document.');

        return $fresh;
    }

    private function makeConfirmedOrder(string $lineDiscountAmount): Document
    {
        /** @phpstan-ignore-next-line argument.type */
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::SalesOrder),
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-TEST-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'description' => 'Service line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'discount_amount' => $lineDiscountAmount,
            'tax_rate' => '20.00',
            'line_total' => '100.00',
            // Mark as services-only so SalesOrderToInvoiceConverter skips the
            // delivery-note compliance check (services_only scenario).
        ]);

        $fresh = $order->fresh(['lines']);
        $this->assertNotNull($fresh, 'Failed to refresh seeded order document.');

        return $fresh;
    }
}
