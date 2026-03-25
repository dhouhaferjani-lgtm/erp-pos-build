<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\SalesWithholdingTracking;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Integration tests for Sales Withholding Tracking
 *
 * Tests the complete sales withholding tracking workflow.
 */
class SalesWithholdingTrackingTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);

        // Seed permissions for this tenant
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->customer = Partner::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create([
                'type' => 'customer',
                'country_code' => 'TN',
            ]);
        $this->user = User::factory()->for($this->tenant)->create();

        // Give user invoice permissions
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.update']);

        // Create user-company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_records_withholding_on_sales_invoice(): void
    {
        // Arrange
        $invoice = $this->createInvoice('1000.000');

        // Act
        $response = $this->postJson("/api/v1/documents/{$invoice->id}/record-withholding", [
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
            'notes' => 'Customer will provide certificate',
        ]);

        // Assert
        $response->assertStatus(201)
            ->assertJsonPath('message', 'Sales withholding recorded successfully')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'documentId',
                    'customerId',
                    'customerName',
                    'invoiceAmount',
                    'withholdingRate',
                    'withholdingAmount',
                    'expectedReceivable',
                    'certificateReceived',
                    'createdAt',
                ],
            ]);

        $this->assertDatabaseHas('sales_withholding_tracking', [
            'company_id' => $this->company->id,
            'document_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.00',
            'withholding_amount' => '100.00',
            'certificate_received' => false,
        ]);
    }

    /** @test */
    public function it_prevents_duplicate_withholding_records(): void
    {
        // Arrange
        $invoice = $this->createInvoice('1000.000');

        // Create first record
        $this->postJson("/api/v1/documents/{$invoice->id}/record-withholding", [
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
        ]);

        // Act - Try to create duplicate
        $response = $this->postJson("/api/v1/documents/{$invoice->id}/record-withholding", [
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
        ]);

        // Assert
        $response->assertStatus(409);
        $this->assertStringContainsString('already recorded', $response->json('message'));
    }

    /** @test */
    public function it_lists_all_tracking_records(): void
    {
        // Arrange - Create 3 tracking records
        for ($i = 1; $i <= 3; $i++) {
            $invoice = $this->createInvoice('1000.000');
            SalesWithholdingTracking::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'document_id' => $invoice->id,
                'customer_id' => $this->customer->id,
                'invoice_amount' => '1000.000',
                'withholding_rate' => '0.1000',
                'withholding_amount' => '100.000',
                'expected_receivable' => '900.000',
                'certificate_received' => false,
            ]);
        }

        // Act
        $response = $this->getJson('/api/v1/sales-withholding');

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_filters_pending_tracking_records(): void
    {
        // Arrange - Create 2 pending and 1 received
        $invoice1 = $this->createInvoice('1000.000');
        SalesWithholdingTracking::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'document_id' => $invoice1->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
            'certificate_received' => false,
        ]);

        $invoice2 = $this->createInvoice('2000.000');
        SalesWithholdingTracking::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'document_id' => $invoice2->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '2000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '200.000',
            'expected_receivable' => '1800.000',
            'certificate_received' => false,
        ]);

        $invoice3 = $this->createInvoice('3000.000');
        SalesWithholdingTracking::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'document_id' => $invoice3->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '3000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '300.000',
            'expected_receivable' => '2700.000',
            'certificate_received' => true,
            'certificate_number' => 'CERT-001',
            'certificate_received_at' => now(),
        ]);

        // Act
        $response = $this->getJson('/api/v1/sales-withholding?filter=pending');

        // Assert
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $record) {
            $this->assertFalse($record['certificateReceived']);
        }
    }

    /** @test */
    public function it_marks_certificate_as_received(): void
    {
        // Arrange
        $invoice = $this->createInvoice('1000.000');
        $tracking = SalesWithholdingTracking::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'document_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
            'certificate_received' => false,
        ]);

        // Act
        $response = $this->patchJson("/api/v1/sales-withholding/{$tracking->id}/certificate-received", [
            'certificate_number' => 'WHT-CUSTOMER-2026-001',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('message', 'Certificate marked as received')
            ->assertJsonPath('data.certificateReceived', true)
            ->assertJsonPath('data.certificateNumber', 'WHT-CUSTOMER-2026-001');

        $this->assertDatabaseHas('sales_withholding_tracking', [
            'id' => $tracking->id,
            'certificate_received' => true,
            'certificate_number' => 'WHT-CUSTOMER-2026-001',
        ]);
    }

    /** @test */
    public function it_prevents_marking_already_received_certificate(): void
    {
        // Arrange
        $invoice = $this->createInvoice('1000.000');
        $tracking = SalesWithholdingTracking::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'document_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'invoice_amount' => '1000.000',
            'withholding_rate' => '0.1000',
            'withholding_amount' => '100.000',
            'expected_receivable' => '900.000',
            'certificate_received' => true,
            'certificate_number' => 'CERT-001',
            'certificate_received_at' => now(),
        ]);

        // Act
        $response = $this->patchJson("/api/v1/sales-withholding/{$tracking->id}/certificate-received", [
            'certificate_number' => 'CERT-002',
        ]);

        // Assert
        $response->assertStatus(409);
        $this->assertStringContainsString('already marked', $response->json('message'));
    }

    /** @test */
    public function it_validates_required_fields(): void
    {
        // Arrange
        $invoice = $this->createInvoice('1000.000');

        // Act
        $response = $this->postJson("/api/v1/documents/{$invoice->id}/record-withholding", []);

        // Assert
        $this->assertApiValidationErrors($response, [
            'customer_id',
            'invoice_amount',
            'withholding_rate',
            'withholding_amount',
            'expected_receivable',
        ]);
    }

    /**
     * Helper: Create a test invoice
     */
    private function createInvoice(string $total): Document
    {
        return Document::factory()->for($this->company)->for($this->customer)->create([
            'type' => 'invoice',
            'total' => $total,
            'currency' => 'TND',
            'status' => 'posted',
        ]);
    }
}
