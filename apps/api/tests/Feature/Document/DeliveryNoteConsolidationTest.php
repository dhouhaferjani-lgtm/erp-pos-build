<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Tests for the Delivery Note Consolidation feature (Tunisia model).
 *
 * This feature allows creating a single invoice from multiple delivery notes,
 * which is required for Tunisian compliance where multiple deliveries to the
 * same customer can be invoiced together at month-end.
 */
class DeliveryNoteConsolidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company TN',
            'legal_name' => 'Test Company SARL',
            'tax_id' => 'TN123456',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['deliveries.view', 'deliveries.create', 'deliveries.confirm', 'invoices.create', 'invoices.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer SARL',
            'type' => PartnerType::Customer,
            'email' => 'customer@example.com',
        ]);
    }

    private function createConfirmedDeliveryNote(string $number, array $lines): Document
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $dn->id,
                'line_number' => $index + 1,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'] ?? '19.00',
                'line_total' => bcmul($line['quantity'], $line['unit_price'], 2),
            ]);
        }

        return $dn;
    }

    public function test_can_consolidate_single_delivery_note_to_invoice(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Oil Change', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'document_number', 'type', 'status', 'lines'],
            'message',
            'meta' => ['consolidated_delivery_notes', 'source_delivery_note_ids'],
        ]);
        $this->assertEquals('invoice', $response->json('data.type'));
        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertCount(1, $response->json('data.lines'));
    }

    public function test_can_consolidate_multiple_delivery_notes_to_single_invoice(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Oil Change', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Brake Pads', 'quantity' => '2.00', 'unit_price' => '80.00'],
            ['description' => 'Labor', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $dn3 = $this->createConfirmedDeliveryNote('DN-2025-0003', [
            ['description' => 'Filter Set', 'quantity' => '1.00', 'unit_price' => '45.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id, $dn3->id],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(3, $response->json('meta.consolidated_delivery_notes'));
        $this->assertCount(4, $response->json('data.lines')); // 1 + 2 + 1 = 4 lines
    }

    public function test_invoice_totals_are_calculated_from_all_delivery_notes(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Part A', 'quantity' => '2.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Part B', 'quantity' => '1.00', 'unit_price' => '300.00', 'tax_rate' => '19.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(201);
        // Part A: 2 * 100 = 200
        // Part B: 1 * 300 = 300
        // Subtotal: 500
        $this->assertEquals('500.00', $response->json('data.subtotal'));
    }

    public function test_delivery_notes_are_marked_as_invoiced_after_consolidation(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(201);

        $dn->refresh();
        $this->assertNotNull($dn->payload['invoiced_at']);
        $this->assertEquals($response->json('data.id'), $dn->payload['invoice_id']);
    }

    public function test_cannot_consolidate_already_invoiced_delivery_note(): void
    {
        $dn = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        // First consolidation
        $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ])->assertStatus(201);

        // Attempt second consolidation with same DN
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_NOTE_ALREADY_INVOICED');
    }

    public function test_cannot_consolidate_delivery_notes_from_different_partners(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Customer',
            'type' => PartnerType::Customer,
            'email' => 'other@example.com',
        ]);

        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherPartner->id, // Different partner
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-2025-0002',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('same partner', $response->json('error.message'));
    }

    public function test_cannot_consolidate_delivery_notes_with_different_currencies(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '100.00'],
        ]);

        // Create DN with EUR instead of TND
        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR', // Different currency
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('same currency', $response->json('error.message'));
    }

    public function test_cannot_consolidate_draft_delivery_note(): void
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft, // Draft, not confirmed
            'document_number' => 'DN-2025-0001',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CONSOLIDATION_VALIDATION_FAILED');
        $this->assertStringContainsString('confirmed', $response->json('error.message'));
    }

    public function test_validation_requires_at_least_one_delivery_note(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [],
        ]);

        $this->assertApiValidationErrors($response, ['delivery_note_ids']);
    }

    public function test_validation_requires_valid_document_ids(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => ['not-a-uuid'],
        ]);

        $this->assertApiValidationErrors($response, ['delivery_note_ids.0']);
    }

    public function test_invoice_reference_contains_all_dn_numbers(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote('DN-2025-0001', [
            ['description' => 'Service A', 'quantity' => '1.00', 'unit_price' => '50.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote('DN-2025-0002', [
            ['description' => 'Service B', 'quantity' => '1.00', 'unit_price' => '75.00'],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$dn1->id, $dn2->id],
        ]);

        $response->assertStatus(201);
        $reference = $response->json('data.reference');
        $this->assertStringContainsString('DN-2025-0001', $reference);
        $this->assertStringContainsString('DN-2025-0002', $reference);
    }
}
