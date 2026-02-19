<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentCacheValidationService;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentCacheValidationTest extends TestCase
{
    use RefreshDatabase;

    private DocumentCacheValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Cache validation tests use complex SQL that may not work consistently across all databases
        // Skip on non-PostgreSQL databases since this feature uses PostgreSQL-specific triggers in production
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cache validation tests are designed for PostgreSQL. SQLite may have compatibility issues with complex subqueries.');
        }

        $this->service = new DocumentCacheValidationService;
    }

    public function test_finds_no_inconsistencies_on_fresh_data(): void
    {
        $company = $this->getTestCompany();

        // Create invoices with correct cache
        Document::factory()->posted()->count(10)->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $inconsistencies = $this->service->findInconsistencies($company->id);

        $this->assertEmpty($inconsistencies);
    }

    public function test_detects_manually_corrupted_cache(): void
    {
        $company = $this->getTestCompany();

        $invoice = Document::factory()->posted()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $payment = Payment::factory()->create([
            'company_id' => $company->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '50.00',
        ]);

        // Manually corrupt cache (bypass trigger)
        DB::table('documents')
            ->where('id', $invoice->id)
            ->update(['balance_due' => '75.00']); // Wrong value (should be 50.00)

        $inconsistencies = $this->service->findInconsistencies($company->id);

        $this->assertCount(1, $inconsistencies);
        $this->assertEquals('75.00', $inconsistencies[0]->cached_balance);
        $this->assertEquals('50.00', $inconsistencies[0]->computed_balance);
        $this->assertEquals('25.00', $inconsistencies[0]->difference);
    }

    public function test_repair_fixes_inconsistencies(): void
    {
        $company = $this->getTestCompany();

        $invoice = Document::factory()->posted()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '200.00',
            'balance_due' => '200.00',
        ]);

        $payment = Payment::factory()->create([
            'company_id' => $company->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '120.00',
        ]);

        // Corrupt cache
        DB::table('documents')
            ->where('id', $invoice->id)
            ->update(['balance_due' => '150.00']);

        // Verify corruption detected
        $inconsistenciesBefore = $this->service->findInconsistencies($company->id);
        $this->assertCount(1, $inconsistenciesBefore);

        // Repair
        $repairedCount = $this->service->repairCache($company->id);
        $this->assertEquals(1, $repairedCount);

        // Verify fixed
        $inconsistenciesAfter = $this->service->findInconsistencies($company->id);
        $this->assertEmpty($inconsistenciesAfter);

        // Verify correct value
        $invoice = $invoice->fresh();
        $this->assertEquals('80.00', $invoice->balance_due);
    }

    public function test_cache_accuracy_report(): void
    {
        $company = $this->getTestCompany();

        // Create 10 accurate invoices
        Document::factory()->posted()->count(10)->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        // Create 2 invoices with corrupted cache
        $invoice1 = Document::factory()->posted()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $invoice2 = Document::factory()->posted()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        // Corrupt 2 invoices
        DB::table('documents')
            ->whereIn('id', [$invoice1->id, $invoice2->id])
            ->update(['balance_due' => '50.00']);

        $report = $this->service->getCacheAccuracyReport($company->id);

        $this->assertEquals(12, $report['total_invoices']);
        $this->assertEquals(10, $report['accurate_invoices']);
        $this->assertEquals(2, $report['inconsistent_invoices']);
        $this->assertEquals(83.33, $report['accuracy_percentage']); // 10/12 * 100
        $this->assertEquals('50.00', $report['max_difference']);
    }

    public function test_validate_all_companies(): void
    {
        $company1 = $this->getTestCompany();
        $company2 = $this->getTestCompany();

        // Create accurate invoices for company 1
        Document::factory()->posted()->count(5)->create([
            'company_id' => $company1->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        // Create accurate invoices for company 2
        Document::factory()->posted()->count(3)->create([
            'company_id' => $company2->id,
            'type' => DocumentType::Invoice,
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);

        $results = $this->service->validateAllCompanies();

        $this->assertGreaterThanOrEqual(2, $results->count());

        $company1Report = $results->firstWhere('company_id', $company1->id);
        $company2Report = $results->firstWhere('company_id', $company2->id);

        $this->assertEquals(5, $company1Report['total_invoices']);
        $this->assertEquals(100.0, $company1Report['accuracy_percentage']);

        $this->assertEquals(3, $company2Report['total_invoices']);
        $this->assertEquals(100.0, $company2Report['accuracy_percentage']);
    }

    public function test_ignores_draft_and_cancelled_invoices(): void
    {
        $company = $this->getTestCompany();

        // Create draft invoice
        $draft = Document::factory()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'total' => '100.00',
            'balance_due' => '999.00', // Intentionally wrong
        ]);

        // Create cancelled invoice
        $cancelled = Document::factory()->create([
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Cancelled,
            'total' => '100.00',
            'balance_due' => '888.00', // Intentionally wrong
        ]);

        // Validation should ignore non-posted invoices
        $inconsistencies = $this->service->findInconsistencies($company->id);
        $this->assertEmpty($inconsistencies);
    }

    private function getTestCompany()
    {
        $tenant = \App\Modules\Tenant\Domain\Tenant::first();
        if (! $tenant) {
            $tenant = \App\Modules\Tenant\Domain\Tenant::factory()->create();
        }

        return \App\Modules\Company\Domain\Company::factory()->create(['tenant_id' => $tenant->id]);
    }
}
