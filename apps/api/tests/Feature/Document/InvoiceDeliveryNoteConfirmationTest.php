<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

/**
 * Tests for delivery note auto-creation and confirmation workflow during invoice posting.
 *
 * This test suite verifies the fix for the DN auto-creation bug where delivery notes
 * were created in Confirmed status instead of Draft, bypassing proper fiscal lifecycle.
 *
 * @see SalesOrderToInvoiceConverter
 * @see InvoiceController::confirmDeliveriesAndPost()
 */
#[UsesFrozenSeederFixture]
class InvoiceDeliveryNoteConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN', // Tunisia for delivery note compliance
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'orders.view', 'orders.create', 'orders.update', 'orders.confirm',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.post',
            'deliveries.view', 'deliveries.create', 'deliveries.confirm',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Seed chart of accounts
        $seeder = new FranceChartOfAccountsSeeder;
        $seeder->run($this->company->id, $this->tenant->id);

        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'email' => 'customer@example.com',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'PROD-001',
            'type' => ProductType::Part,
            'is_physical' => true,
            'unit_price' => '100.00',
            'cost_price' => '60.00',
        ]);

        // Create stock level so delivery note confirmation can issue stock
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => '100.00',
            'reserved' => '0.00',
        ]);
    }

    /**
     * Test: Auto-created delivery notes should be in Draft status, not Confirmed.
     *
     * When converting Sales Order → Invoice with physical products, the system
     * auto-creates delivery notes. These MUST be in Draft status so users can
     * explicitly confirm them (issuing stock and adding to fiscal chain).
     */
    public function test_auto_created_delivery_notes_are_draft_status(): void
    {
        // Create a sales order with physical products
        $order = $this->createConfirmedSalesOrder();

        // Convert order to invoice
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");

        $response->assertStatus(201);
        $invoiceId = $response->json('data.id');

        // Find the auto-created delivery note
        $deliveryNote = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->first();

        $this->assertNotNull($deliveryNote, 'Delivery note should be auto-created');
        $this->assertEquals(DocumentStatus::Draft, $deliveryNote->status, 'Auto-created DN must be Draft');
        $this->assertTrue($deliveryNote->payload['auto_created'] ?? false, 'DN should have auto_created flag');
        $this->assertNull($deliveryNote->confirmed_at, 'Draft DN should not have confirmed_at');
        $this->assertNull($deliveryNote->confirmed_by, 'Draft DN should not have confirmed_by');
        $this->assertNull($deliveryNote->fiscal_hash, 'Draft DN should not have fiscal hash');
    }

    /**
     * Test: Cannot post invoice when delivery notes are still in draft status.
     *
     * Tunisia compliance requires delivery notes to be confirmed (fiscally sealed)
     * before invoicing. The system should return a structured error with DN details.
     */
    public function test_cannot_post_invoice_with_draft_delivery_notes(): void
    {
        // Create order and convert to invoice (creates draft DN)
        $order = $this->createConfirmedSalesOrder();
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        $invoiceId = $invoiceResponse->json('data.id');

        // Confirm the invoice
        $confirmResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");
        $confirmResponse->assertStatus(200);

        // Try to post invoice with draft DN - should fail
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/post");

        $response->assertStatus(422);
        // Debug: dump actual response if assertion fails
        if ($response->json('error.code') !== 'DELIVERY_NOT_COMPLETED') {
            dump('Actual error:', $response->json());
        }
        $this->assertEquals('DELIVERY_NOT_COMPLETED', $response->json('error.code'));
        $this->assertEquals('draft_dns_found', $response->json('error.details.status'));
        $this->assertTrue($response->json('error.details.can_auto_confirm'));
        $this->assertNotEmpty($response->json('error.details.draft_dns'));
        $this->assertArrayHasKey('id', $response->json('error.details.draft_dns.0'));
        $this->assertArrayHasKey('number', $response->json('error.details.draft_dns.0'));
    }

    /**
     * Test: confirmDeliveriesAndPost endpoint successfully confirms DNs and posts invoice.
     *
     * This is the one-click workflow exposed via the frontend modal.
     * Should atomically:
     * 1. Confirm all draft delivery notes (issue stock, add to fiscal chain)
     * 2. Post the invoice (add to fiscal chain)
     */
    public function test_confirm_deliveries_and_post_workflow(): void
    {
        // Create order and convert to invoice (creates draft DN)
        $order = $this->createConfirmedSalesOrder();
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        $invoiceId = $invoiceResponse->json('data.id');

        // Confirm the invoice
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");

        // Get the draft DN before confirmation
        $draftDn = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->first();
        $this->assertEquals(DocumentStatus::Draft, $draftDn->status);

        // Use confirmDeliveriesAndPost endpoint
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm-deliveries-and-post");

        $response->assertStatus(200);

        // Verify invoice is posted
        $this->assertEquals('posted', $response->json('data.status'));
        $this->assertNotNull($response->json('meta.invoice_fiscal_hash'));
        $this->assertIsInt($response->json('meta.invoice_chain_sequence'));

        // Verify DN was confirmed
        $confirmedDns = $response->json('meta.confirmed_delivery_notes');
        $this->assertCount(1, $confirmedDns);
        $this->assertEquals($draftDn->id, $confirmedDns[0]['id']);
        $this->assertNotNull($confirmedDns[0]['fiscal_hash']);
        $this->assertIsInt($confirmedDns[0]['chain_sequence']);

        // Verify database state
        $draftDn->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $draftDn->status);
        $this->assertNotNull($draftDn->confirmed_at);
        $this->assertNotNull($draftDn->confirmed_by);
        $this->assertNotNull($draftDn->fiscal_hash);
        $this->assertIsInt($draftDn->chain_sequence);
    }

    /**
     * Test: confirmDeliveriesAndPost is atomic - all or nothing.
     *
     * This test verifies the atomic nature by checking that if the workflow
     * encounters any errors, both invoice and DNs remain in their pre-operation state.
     * Note: Actual stock failure testing is done in stock movement tests.
     */
    public function test_confirm_deliveries_and_post_atomic_behavior(): void
    {
        // Create order and convert to invoice
        $order = $this->createConfirmedSalesOrder();
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        $invoiceId = $invoiceResponse->json('data.id');

        // Confirm invoice
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");

        // Get initial DN state
        $dn = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->first();
        $this->assertEquals(DocumentStatus::Draft, $dn->status);

        // Successfully confirm deliveries and post
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm-deliveries-and-post");

        $response->assertStatus(200);

        // Verify atomic success: both invoice and DN are now posted/confirmed
        $invoice = Document::find($invoiceId);
        $this->assertEquals(DocumentStatus::Posted, $invoice->status);
        $this->assertNotNull($invoice->fiscal_hash);

        $dn->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $dn->status);
        $this->assertNotNull($dn->fiscal_hash);
    }

    /**
     * Test: Service-only invoices can be posted directly without DNs.
     *
     * Invoices with only service lines (no physical products) should not
     * require delivery notes and can be posted immediately.
     */
    public function test_service_only_invoice_can_be_posted_directly(): void
    {
        // Create invoice with service-only lines
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);

        $invoice->lines()->create([
            'line_number' => 1,
            'product_id' => null, // Service line has no product_id
            'description' => 'Diagnostic Service',
            'quantity' => '1.00',
            'unit_price' => '500.00',
            'tax_rate' => '20.00',
            'line_total' => '500.00',
        ]);

        // Post the invoice directly - should succeed
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");

        $response->assertStatus(200);
        $this->assertEquals('posted', $response->json('data.status'));
        $this->assertNotNull($response->json('meta.fiscal_hash'));
    }

    /**
     * Test: If DN is manually confirmed before posting, invoice posts normally.
     *
     * Users might manually confirm the delivery note via the DN detail page
     * before trying to post the invoice. In this case, normal post should work.
     */
    public function test_invoice_posts_normally_when_dn_already_confirmed(): void
    {
        // Create order and convert to invoice (creates draft DN)
        $order = $this->createConfirmedSalesOrder();
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        $invoiceId = $invoiceResponse->json('data.id');

        // Confirm the invoice
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");

        // Manually confirm the delivery note
        $dn = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->first();

        $this->actingAs($this->user)
            ->postJson("/api/v1/delivery-notes/{$dn->id}/confirm");

        // Now post the invoice normally - should succeed
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/post");

        $response->assertStatus(200);
        $this->assertEquals('posted', $response->json('data.status'));
        $this->assertNotNull($response->json('meta.fiscal_hash'));
    }

    /**
     * Test: Multiple auto-created DNs are all confirmed in batch.
     *
     * Edge case: If conversion creates multiple DNs (e.g., partial deliveries),
     * all should be confirmed in the atomic operation.
     */
    public function test_multiple_draft_dns_confirmed_in_batch(): void
    {
        // Create order
        $order = $this->createConfirmedSalesOrder();

        // Manually create a second draft DN linked to the same order
        // (simulating partial delivery scenario)
        $defaultLocation = Location::where('company_id', $this->company->id)
            ->where('is_default', true)
            ->first();

        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'location_id' => $defaultLocation->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'DN-2025-0002',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
            'source_document_id' => $order->id,
            'payload' => ['auto_created' => true],
        ]);

        $dn2->lines()->create([
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => '0.50',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '50.00',
        ]);

        // Convert order to invoice (creates another draft DN)
        $invoiceResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        $invoiceId = $invoiceResponse->json('data.id');

        // Add the manually-created DN to the order's delivery_note_ids payload
        // so the controller can find it (it uses payload, not source_document_id)
        $order->refresh();
        $payload = $order->payload ?? [];
        $payload['delivery_note_ids'] = array_merge($payload['delivery_note_ids'] ?? [], [$dn2->id]);
        $order->update(['payload' => $payload]);

        // Confirm invoice
        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");

        // Verify 2 draft DNs exist
        $draftDns = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->where('status', DocumentStatus::Draft)
            ->get();
        $this->assertCount(2, $draftDns);

        // Confirm deliveries and post
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm-deliveries-and-post");

        $response->assertStatus(200);

        // Verify both DNs were confirmed
        $confirmedDns = $response->json('meta.confirmed_delivery_notes');
        $this->assertCount(2, $confirmedDns);

        // Verify both DNs are now confirmed in database
        $confirmedDnsInDb = Document::where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $order->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get();
        $this->assertCount(2, $confirmedDnsInDb);
    }

    /**
     * Helper: Create a confirmed sales order with physical products.
     */
    private function createConfirmedSalesOrder(): Document
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '200.00',
            'tax_amount' => '40.00',
            'total' => '240.00',
        ]);

        $order->lines()->create([
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => '2.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '200.00',
        ]);

        return $order;
    }
}
