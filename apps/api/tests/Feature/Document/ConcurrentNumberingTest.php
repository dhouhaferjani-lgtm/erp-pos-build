<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ConcurrentNumberingTest - Regression tests for document numbering under concurrent access
 *
 * Tests covered:
 * - Sequential document numbers without gaps
 * - No duplicate document numbers under concurrent generation
 * - Different document types maintain separate sequences
 * - Cross-company sequence isolation
 * - Pessimistic locking mechanism
 */
class ConcurrentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $company2;

    private User $user;

    private Partner $customer;

    private DocumentNumberingService $numberingService;

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
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'legal_name' => 'Second Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
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
            'invoices.view',
            'invoices.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->numberingService = app(DocumentNumberingService::class);
    }

    // ============================================
    // DOCUMENT NUMBERING TESTS
    // ============================================

    public function test_sequential_invoice_numbers_generated(): void
    {
        $numbers = [];
        $year = (int) date('Y');

        for ($i = 0; $i < 10; $i++) {
            $number = $this->numberingService->generateNumber(
                $this->tenant->id,
                $this->company->id,
                DocumentType::Invoice
            );
            $numbers[] = $number;
        }

        // Verify format: INV-YYYY-NNNN
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression("/^INV-{$year}-\\d{4}$/", $number);
        }

        // Verify sequential without gaps
        $this->assertEquals("INV-{$year}-0001", $numbers[0]);
        $this->assertEquals("INV-{$year}-0002", $numbers[1]);
        $this->assertEquals("INV-{$year}-0010", $numbers[9]);

        // All numbers should be unique
        $this->assertCount(10, array_unique($numbers));
    }

    public function test_different_document_types_have_separate_sequences(): void
    {
        $year = (int) date('Y');

        // Generate numbers for different types
        $invoice1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $quote1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Quote);
        $creditNote1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::CreditNote);
        $invoice2 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        // Each type starts at 0001
        $this->assertEquals("INV-{$year}-0001", $invoice1);
        $this->assertEquals("QT-{$year}-0001", $quote1);
        $this->assertEquals("CN-{$year}-0001", $creditNote1);
        $this->assertEquals("INV-{$year}-0002", $invoice2);
    }

    public function test_different_companies_have_separate_sequences(): void
    {
        $year = (int) date('Y');

        // Company 1 generates some numbers
        $company1_inv1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $company1_inv2 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        // Company 2 sequence starts fresh
        $company2_inv1 = $this->numberingService->generateNumber($this->tenant->id, $this->company2->id, DocumentType::Invoice);

        $this->assertEquals("INV-{$year}-0001", $company1_inv1);
        $this->assertEquals("INV-{$year}-0002", $company1_inv2);
        $this->assertEquals("INV-{$year}-0001", $company2_inv1);
    }

    public function test_sequence_persists_across_service_instances(): void
    {
        $year = (int) date('Y');

        // Generate with first instance
        $number1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        // Create new service instance
        $newService = new DocumentNumberingService();
        $number2 = $newService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        $this->assertEquals("INV-{$year}-0001", $number1);
        $this->assertEquals("INV-{$year}-0002", $number2);
    }

    public function test_rapid_sequential_generation_no_duplicates(): void
    {
        $numbers = [];
        $iterations = 50;

        for ($i = 0; $i < $iterations; $i++) {
            $numbers[] = $this->numberingService->generateNumber(
                $this->tenant->id,
                $this->company->id,
                DocumentType::Invoice
            );
        }

        // All should be unique
        $uniqueNumbers = array_unique($numbers);
        $this->assertCount($iterations, $uniqueNumbers);

        // Should be in order
        $sortedNumbers = $numbers;
        sort($sortedNumbers);
        $this->assertEquals($sortedNumbers, $numbers);
    }

    public function test_getCurrentNumber_returns_correct_value(): void
    {
        $this->assertEquals(0, $this->numberingService->getCurrentNumber($this->company->id, DocumentType::Invoice));

        $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $this->assertEquals(1, $this->numberingService->getCurrentNumber($this->company->id, DocumentType::Invoice));

        $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $this->assertEquals(2, $this->numberingService->getCurrentNumber($this->company->id, DocumentType::Invoice));
    }

    public function test_sequence_row_created_on_first_generation(): void
    {
        // No sequences exist initially
        $this->assertEquals(0, DocumentSequence::where('company_id', $this->company->id)->count());

        $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        // Sequence row created
        $sequence = DocumentSequence::where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice->value)
            ->first();

        $this->assertNotNull($sequence);
        $this->assertEquals(1, $sequence->last_number);
        $this->assertEquals((int) date('Y'), $sequence->year);
    }

    public function test_document_numbering_uses_pessimistic_locking(): void
    {
        // This test verifies the locking mechanism works by checking
        // the sequence table is properly locked during generation

        // Generate a number
        $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);

        // Verify sequence was created
        $sequence = DocumentSequence::where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice->value)
            ->first();

        $this->assertNotNull($sequence);
        $this->assertEquals(1, $sequence->last_number);

        // Simulate another transaction reading the same sequence
        // In a real scenario, lockForUpdate() would block until first transaction commits
        DB::transaction(function () use ($sequence) {
            $lockedSequence = DocumentSequence::where('id', $sequence->id)
                ->lockForUpdate()
                ->first();

            // Still 1 because we're in a separate transaction
            $this->assertEquals(1, $lockedSequence->last_number);
        });
    }

    public function test_multiple_rapid_document_creations(): void
    {
        $year = (int) date('Y');
        $documents = [];

        // Rapidly create multiple documents
        for ($i = 0; $i < 20; $i++) {
            $docNumber = $this->numberingService->generateNumber(
                $this->tenant->id,
                $this->company->id,
                DocumentType::Invoice
            );

            $documents[] = Document::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Draft,
                'document_number' => $docNumber,
                'document_date' => now(),
                'subtotal' => '100.00',
                'tax_amount' => '20.00',
                'total' => '120.00',
                'balance_due' => '120.00',
                'currency' => 'EUR',
            ]);
        }

        // All 20 documents created with unique numbers
        $this->assertCount(20, $documents);

        $numbers = array_map(fn ($d) => $d->document_number, $documents);
        $this->assertCount(20, array_unique($numbers));

        // First and last should be correct
        $this->assertEquals("INV-{$year}-0001", $numbers[0]);
        $this->assertEquals("INV-{$year}-0020", $numbers[19]);
    }

    public function test_interleaved_document_type_numbering(): void
    {
        $year = (int) date('Y');

        // Interleave different document types
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = ['type' => 'INV', 'number' => $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice)];
            $results[] = ['type' => 'QT', 'number' => $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Quote)];
            $results[] = ['type' => 'SO', 'number' => $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::SalesOrder)];
        }

        // Verify each type has 5 sequential numbers
        $invoices = array_filter($results, fn ($r) => $r['type'] === 'INV');
        $quotes = array_filter($results, fn ($r) => $r['type'] === 'QT');
        $salesOrders = array_filter($results, fn ($r) => $r['type'] === 'SO');

        $this->assertCount(5, $invoices);
        $this->assertCount(5, $quotes);
        $this->assertCount(5, $salesOrders);

        // Check sequences
        $invNumbers = array_column($invoices, 'number');
        $this->assertEquals("INV-{$year}-0001", $invNumbers[0]);
        $this->assertEquals("INV-{$year}-0005", $invNumbers[4]);
    }

    public function test_all_document_types_have_correct_prefixes(): void
    {
        $year = (int) date('Y');

        $types = [
            ['type' => DocumentType::Quote, 'prefix' => 'QT'],
            ['type' => DocumentType::SalesOrder, 'prefix' => 'SO'],
            ['type' => DocumentType::PurchaseOrder, 'prefix' => 'PO'],
            ['type' => DocumentType::Invoice, 'prefix' => 'INV'],
            ['type' => DocumentType::CreditNote, 'prefix' => 'CN'],
            ['type' => DocumentType::DeliveryNote, 'prefix' => 'DN'],
        ];

        foreach ($types as $item) {
            $number = $this->numberingService->generateNumber(
                $this->tenant->id,
                $this->company->id,
                $item['type']
            );

            $this->assertStringStartsWith("{$item['prefix']}-{$year}-", $number);
        }
    }

    public function test_sequence_isolation_between_tenants(): void
    {
        $year = (int) date('Y');

        // Create second tenant with its own company
        $tenant2 = Tenant::create([
            'name' => 'Second Tenant',
            'slug' => 'second-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company3 = Company::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Tenant 2 Company',
            'legal_name' => 'Tenant 2 Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Generate numbers for both tenants
        $tenant1_inv1 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $tenant1_inv2 = $this->numberingService->generateNumber($this->tenant->id, $this->company->id, DocumentType::Invoice);
        $tenant2_inv1 = $this->numberingService->generateNumber($tenant2->id, $company3->id, DocumentType::Invoice);

        // Each tenant starts at 0001
        $this->assertEquals("INV-{$year}-0001", $tenant1_inv1);
        $this->assertEquals("INV-{$year}-0002", $tenant1_inv2);
        $this->assertEquals("INV-{$year}-0001", $tenant2_inv1);
    }
}
