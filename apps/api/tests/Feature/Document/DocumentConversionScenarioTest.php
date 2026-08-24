<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Tunisia fiscal compliance: 3-scenario invoicing.
 *
 * - Services only: Can be invoiced directly from SO
 * - Products only: Must have delivery notes before invoicing
 * - Mixed: Physical items must be delivered before invoicing
 */
class DocumentConversionScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private DocumentConverterRegistry $converterRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create default location (required for SO→Invoice and SO→DN conversions)
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->converterRegistry = app(DocumentConverterRegistry::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    #[Test]
    public function it_allows_direct_invoicing_for_services_only_orders(): void
    {
        // Create a service product (non-physical)
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create sales order with services only
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        // Should allow direct conversion to invoice
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertEquals(DocumentType::Invoice, $invoice->type);
        $this->assertEquals($order->document_number, $invoice->reference);
    }

    #[Test]
    public function it_posts_prepayment_application_when_order_invoice_conversion_has_actor(): void
    {
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conversion User',
            'email' => 'conversion@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $cashAccount = $this->seedPrepaymentApplicationAccounts();
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $cashAccount->id,
            date: now(),
            user: $user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency,
        );
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $order->id,
            'amount' => '40.000',
        ]);

        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice, [
            'actor_user_id' => $user->id,
        ]);

        $entry = JournalEntry::query()
            ->where('source_type', 'prepayment_application')
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);
    }

    #[Test]
    public function it_converts_order_when_transferred_prepayment_is_already_cleared(): void
    {
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stale Advance Conversion User',
            'email' => 'stale-advance-conversion@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $cashAccount = $this->seedPrepaymentApplicationAccounts();
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        $glService = app(GeneralLedgerService::class);
        $glService->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $cashAccount->id,
            date: now(),
            user: $user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency,
        );
        $glService->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: (string) Str::uuid(),
            amount: '40.000',
            date: now(),
            description: 'Advance already cleared',
            postedByUserId: $user->id,
            currencyCode: $this->company->currency,
        );
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $order->id,
            'amount' => '40.000',
        ]);

        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice, [
            'actor_user_id' => $user->id,
        ]);

        $invoice->refresh();
        $allocation = PaymentAllocation::query()->firstOrFail();

        $this->assertSame($invoice->id, $allocation->document_id);
        $this->assertSame('79.000', $invoice->balance_due);
        $this->assertTrue($invoice->payload['prepayments_transferred']['gl_entry_skipped'] ?? false);
        $this->assertStringContainsString(
            'Cannot clear customer advance beyond available balance',
            $invoice->payload['prepayments_transferred']['gl_skip_reason'] ?? ''
        );
        $this->assertFalse(
            JournalEntry::query()
                ->where('source_type', 'prepayment_application')
                ->where('source_id', $invoice->id)
                ->exists()
        );
    }

    /**
     * enforcement-P3 M1 round 1, finding 2 — RENAMED to state what it actually pins.
     *
     * The prepayment transfer wraps a live GL post in a graceful
     * `catch (\InvalidArgumentException|\RuntimeException)`. A BALANCE refusal does
     * not reach that catch — but only because the post is DEFERRED past this frame
     * by `DB::afterCommit` (`GeneralLedgerService.php:96-110`), since
     * `transferPrepayments()` always runs inside the billing retrier's transaction.
     * That protection is incidental, not designed.
     *
     * This test therefore pins the DEFERRAL, not a catch clause: if the enclosing
     * transaction is ever removed the post becomes synchronous, the graceful catch
     * swallows the refusal, no exception surfaces, and this test goes RED — which
     * is exactly when the catch must be narrowed.
     *
     * An earlier version of this test was named `it_does_not_swallow_...` and was
     * presented as covering a narrowing re-throw added to the converter. The review
     * showed that re-throw was unreachable and the test non-discriminating for it
     * (deleting the catch left this green), so the re-throw was WITHDRAWN and this
     * test was re-scoped to the invariant it genuinely discriminates on.
     *
     * The imbalance is produced with real production machinery — an Eloquent
     * `created` hook adds a third, unbalancing leg to the prepayment entry while it
     * is still an unchained Draft (which `JournalLineObserver` permits), so the
     * chokepoint re-reads unbalanced lines and refuses. No mocking: the GL service
     * is `final` and is exercised for real.
     *
     * Census evidence: `docs/handoff/reviews/enforcement-p3/M1-census.md` §5.2, §6 R-7.
     */
    #[Test]
    public function it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud(): void
    {
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unbalanced Prepayment User',
            'email' => 'unbalanced-prepayment@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $cashAccount = $this->seedPrepaymentApplicationAccounts();
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $cashAccount->id,
            date: now(),
            user: $user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency,
        );
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $order->id,
            'amount' => '40.000',
        ]);

        // Unbalance the prepayment entry between line creation and the post.
        $injecting = false;
        JournalLine::created(function (JournalLine $line) use (&$injecting, $cashAccount): void {
            if ($injecting) {
                return;
            }

            $entry = JournalEntry::find($line->journal_entry_id);
            if ($entry === null
                || $entry->source_type !== 'prepayment_application'
                || $entry->status !== JournalEntryStatus::Draft) {
                return;
            }

            $injecting = true;
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cashAccount->id,
                'debit' => '7.000',
                'credit' => '0',
                'description' => 'Injected unbalancing leg',
                'line_order' => 99,
            ]);
            $injecting = false;
        });

        $this->expectException(UnbalancedJournalEntryPostException::class);

        $this->converterRegistry->convert($order, DocumentType::Invoice, [
            'actor_user_id' => $user->id,
        ]);
    }

    #[Test]
    public function it_auto_creates_delivery_note_for_products_only_orders(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products only
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Should auto-create a delivery note and proceed with invoicing
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[Test]
    public function it_auto_creates_delivery_note_for_mixed_orders(): void
    {
        // Create both service and physical product
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create mixed sales order
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
            ['product_id' => $part->id, 'description' => 'Oil Filter'],
        ]);

        // Should auto-create a delivery note for physical items and proceed
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[Test]
    public function it_allows_invoicing_after_delivery_for_products(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note first
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

        // Refresh order to get updated payload
        $order->refresh();

        // Now invoicing should be allowed
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[Test]
    public function it_prevents_duplicate_invoicing(): void
    {
        // Create a service product (non-physical)
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create and invoice a services-only order
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        // First conversion should succeed
        $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Refresh order to get updated payload
        $order->refresh();

        // Second conversion should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales order has already been fully invoiced');

        $this->converterRegistry->convert($order, DocumentType::Invoice);
    }

    #[Test]
    public function it_allows_invoicing_manual_lines_without_product(): void
    {
        // Create sales order with manual lines (no product_id)
        $order = $this->createConfirmedOrder([
            ['product_id' => null, 'description' => 'Custom Service'],
        ]);

        // Manual lines without product_id should be treated as services
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[Test]
    public function it_marks_delivery_notes_as_invoiced_when_converting_order_to_invoice(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note first (required for physical products)
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

        // Verify DN is not yet marked as invoiced
        $delivery->refresh();
        $this->assertNull($delivery->payload['invoiced_at'] ?? null);

        // Refresh order and convert to invoice
        $order->refresh();
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Verify DN is now marked as invoiced
        $delivery->refresh();
        $this->assertNotNull($delivery->payload['invoiced_at'] ?? null);
        $this->assertEquals($invoice->id, $delivery->payload['invoice_id'] ?? null);
        $this->assertEquals('order_conversion', $delivery->payload['invoiced_via'] ?? null);
    }

    #[Test]
    public function it_does_not_show_invoiced_dns_in_consolidation_after_order_conversion(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);
        $delivery->update(['status' => DocumentStatus::Confirmed]);

        // Query for uninvoiced delivery notes (simulating DN consolidation page query)
        $uninvoicedDns = Document::where('type', DocumentType::DeliveryNote)
            ->where('partner_id', $this->partner->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get()
            ->filter(fn ($dn) => empty($dn->payload['invoiced_at']));

        // Before converting SO to invoice, DN should be available for consolidation
        $this->assertCount(1, $uninvoicedDns);

        // Convert order to invoice
        $order->refresh();
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Query again for uninvoiced delivery notes
        $uninvoicedDnsAfter = Document::where('type', DocumentType::DeliveryNote)
            ->where('partner_id', $this->partner->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get()
            ->filter(fn ($dn) => empty($dn->payload['invoiced_at']));

        // After converting SO to invoice, DN should NOT be available for consolidation
        $this->assertCount(0, $uninvoicedDnsAfter);
    }

    #[Test]
    public function quote_to_order_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create quote with vehicle context
        $quote = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-'.time().'-'.rand(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $quote->id,
            'line_number' => 1,
            'product_id' => $service->id,
            'description' => 'Oil Change',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        DocumentVehicleContext::create([
            'document_id' => $quote->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $quote->refresh();

        // Convert quote to order
        $order = $this->converterRegistry->convert($quote, DocumentType::SalesOrder);

        // Verify vehicle context was NOT preserved (conversion service doesn't copy it yet)
        $this->assertNotNull($quote->vehicle_id);
        $this->assertEquals($vehicle->id, $quote->vehicle_id);

        // The order should have vehicle context copied from quote
        $this->assertNotNull($order->vehicleContext);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
    }

    #[Test]
    public function order_to_delivery_note_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create order with vehicle context
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        DocumentVehicleContext::create([
            'document_id' => $order->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $order->refresh();

        // Convert to delivery note
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

        // Verify vehicle context was preserved
        $this->assertNotNull($order->vehicle_id);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
        $this->assertNotNull($delivery->vehicle_id);
        $this->assertEquals($vehicle->id, $delivery->vehicle_id);
    }

    #[Test]
    public function order_to_invoice_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create services-only order with vehicle context
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change'],
        ]);

        DocumentVehicleContext::create([
            'document_id' => $order->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $order->refresh();

        // Convert to invoice (allowed for services-only orders)
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Verify vehicle context was preserved
        $this->assertNotNull($order->vehicle_id);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
        $this->assertNotNull($invoice->vehicle_id);
        $this->assertEquals($vehicle->id, $invoice->vehicle_id);
    }

    /**
     * N-6 fix round r1 — gate C-2 / F-3 [CRITICAL].
     *
     * The converter used to stamp `advance_cleared_at` on the strength of
     * `clearCustomerAdvanceToReceivable()` returning without throwing. With the
     * legacy `PostingMode::AfterCommit` and a NULL actor that call takes NEITHER
     * posting branch: the entry is created DRAFT and never posted, while the
     * allocation is marked cleared anyway. `clearAdvancesAllocatedToInvoice()`
     * filters `whereNull('advance_cleared_at')` and therefore SKIPS at posting —
     * so 419 stays credited forever, the invoice's fresh 411 is never
     * discharged, and nothing errors. The lane's own safety net, disarmed in
     * exactly its documented failure mode.
     */
    #[Test]
    public function it_posts_the_prepayment_clearing_even_without_an_actor_and_only_then_marks_it_cleared(): void
    {
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);
        $cashAccount = $this->seedPrepaymentApplicationAccounts();
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $cashAccount->id,
            date: now(),
            user: null,
            description: 'Customer advance payment, no resolvable actor',
            currencyCode: $this->company->currency,
        );
        $allocation = PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $order->id,
            'amount' => '40.000',
            'booked_as_advance' => true,
        ]);

        // NO `actor_user_id` — the nullable path.
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $entry = JournalEntry::query()
            ->where('source_type', 'prepayment_application')
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(
            JournalEntryStatus::Posted,
            $entry->status,
            'a clearing entry left DRAFT discharges nothing — it must post synchronously',
        );

        $allocation->refresh();
        $this->assertNotNull(
            $allocation->advance_cleared_at,
            'cleared-ness is claimed from a POSTED entry, and this one posted',
        );
        $this->assertSame($entry->id, $allocation->advance_journal_entry_id);
    }

    /**
     * The REAL converter probe (gate I-9). The r1 suite simulated this by
     * hand-writing `advance_cleared_at`, which is how C-2 survived.
     *
     * SCOPE, stated rather than implied: this class's fixture builds a MINIMAL
     * chart (bank / 411 / 419 only), so the invoice cannot be posted here — the
     * GL pre-flight needs revenue and VAT accounts. What this proves is the
     * converter's whole contribution: exactly ONE clearing entry, POSTED, and
     * the marker that the posting path reads. The other half — that posting
     * does NOT clear a second time once that marker is set — is proved on the
     * full TN chart by
     * `N6PaymentOnUnpostedInvoiceTest::test_a_prepayment_transferred_from_an_order_is_not_cleared_again_at_posting`.
     */
    #[Test]
    public function it_clears_an_order_prepayment_exactly_once_at_conversion(): void
    {
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conversion User',
            'email' => 'single-clearing-'.bin2hex(random_bytes(3)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $cashAccount = $this->seedPrepaymentApplicationAccounts();
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        app(GeneralLedgerService::class)->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $cashAccount->id,
            date: now(),
            user: $user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency,
        );
        PaymentAllocation::create([
            'payment_id' => null,
            'document_id' => $order->id,
            'amount' => '40.000',
            'booked_as_advance' => true,
        ]);

        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice, [
            'actor_user_id' => $user->id,
        ]);

        $entries = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'prepayment_application')
            ->where('source_id', $invoice->id)
            ->get();

        $this->assertCount(
            1,
            $entries,
            'a second clearing would drain another advance of the same partner (the ceiling is partner-pool-level)',
        );
        $this->assertSame(JournalEntryStatus::Posted, $entries->first()?->status);

        $allocation = PaymentAllocation::query()->where('document_id', $invoice->id)->sole();
        $this->assertTrue($allocation->booked_as_advance);
        $this->assertNotNull(
            $allocation->advance_cleared_at,
            'the marker the posting path reads must be set, and only because the entry POSTED',
        );
    }

    private function seedPrepaymentApplicationAccounts(): Account
    {
        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512',
            'name' => 'Bank',
            'type' => 'asset',
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4191',
            'name' => 'Customer Advances',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::CustomerAdvance,
            'is_active' => true,
        ]);

        return $cashAccount;
    }

    /**
     * Create a confirmed sales order with the given lines.
     *
     * @param  array<int, array{product_id: string|null, description: string}>  $lines
     */
    private function createConfirmedOrder(array $lines): Document
    {
        $order = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.time().'-'.rand(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);

        foreach ($lines as $index => $lineData) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $order->id,
                'line_number' => $index + 1,
                'product_id' => $lineData['product_id'],
                'description' => $lineData['description'],
                'quantity' => '1.00',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'line_total' => '100.00',
            ]);
        }

        return $order;
    }
}
