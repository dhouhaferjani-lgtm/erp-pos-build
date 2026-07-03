<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartiesImportBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private ImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-parties-balances',
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
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import Admin',
            'email' => 'import-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->importService = app(ImportService::class);
    }

    public function test_parties_import_posts_ar_and_ap_opening_balance_batches(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['name' => 'Acme Corp', 'type' => 'customer', 'opening_balance' => '100', 'reference' => 'LEG-AR-1'],
            2 => ['name' => 'Credit Customer', 'type' => 'customer', 'code' => 'CUST-NEG', 'opening_balance' => '-50'],
            3 => ['name' => 'Parts Supplier', 'type' => 'supplier', 'code' => 'SUP-POS', 'opening_balance' => '80'],
            4 => ['name' => 'Partner Only', 'type' => 'customer', 'code' => 'NO-BAL'],
        ]);

        $this->importService->executeImport($job);

        $this->assertSame(4, Partner::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());

        $generatedCode = (string) $job->rows()->where('row_number', 1)->firstOrFail()->refresh()->data['code'];
        $this->assertStringStartsWith('IMP-'.substr($job->id, 0, 8).'-1', $generatedCode);
        $this->assertDatabaseHas('partners', [
            'company_id' => $this->company->id,
            'code' => $generatedCode,
            'name' => 'Acme Corp',
        ]);

        $invoice = Document::where('partner_id', Partner::where('code', $generatedCode)->value('id'))->firstOrFail();
        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame('100.000', $invoice->total);

        $creditNote = Document::where('partner_id', Partner::where('code', 'CUST-NEG')->value('id'))->firstOrFail();
        $this->assertSame(DocumentType::CreditNote, $creditNote->type);
        $this->assertSame('50.000', $creditNote->total);

        $supplierInvoice = Document::where('partner_id', Partner::where('code', 'SUP-POS')->value('id'))->firstOrFail();
        $this->assertSame(DocumentType::Invoice, $supplierInvoice->type);
        $this->assertSame('80.000', $supplierInvoice->total);

        $arBatch = OpeningBalanceBatch::where('type', OpeningBatchType::ArOpenItems)->firstOrFail();
        $apBatch = OpeningBalanceBatch::where('type', OpeningBatchType::ApOpenItems)->firstOrFail();
        $this->assertSame(OpeningBatchStatus::Validated, $arBatch->status);
        $this->assertSame(OpeningBatchStatus::Validated, $apBatch->status);
        $this->assertSame('IMPORT-'.substr($job->id, 0, 8).'-AR', $arBatch->name);
        $this->assertSame('IMPORT-'.substr($job->id, 0, 8).'-AP', $apBatch->name);
        $this->assertSame(['import_job_id' => $job->id, 'source' => 'unified-import'], $arBatch->import_file_reference);
        $this->assertSame(['import_job_id' => $job->id, 'source' => 'unified-import'], $apBatch->import_file_reference);

        $rowOne = $job->rows()->where('row_number', 1)->firstOrFail()->refresh();
        $rowFour = $job->rows()->where('row_number', 4)->firstOrFail()->refresh();
        $this->assertNull($rowOne->warnings);
        $this->assertSame('ok', $rowOne->data['_results']['ar_balance']);
        $this->assertNull($rowFour->warnings);
        $this->assertArrayNotHasKey('_results', $rowFour->data);
    }

    public function test_unlocked_ar_batch_records_balance_warning_without_blocking_partner_import(): void
    {
        app(OpeningBalanceBatchService::class)->createBatch(
            $this->company,
            OpeningBatchType::ArOpenItems,
            now(),
            'Manual AR',
            $this->user->id,
            'manual'
        );
        $job = $this->makeValidatedJob([
            1 => ['name' => 'Acme Corp', 'type' => 'customer', 'code' => 'CUST-001', 'opening_balance' => '100'],
        ]);

        $this->importService->executeImport($job);

        $this->assertSame(1, Partner::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());
        $this->assertSame(1, OpeningBalanceBatch::where('type', OpeningBatchType::ArOpenItems)->count());

        $row = $job->rows()->firstOrFail()->refresh();
        $this->assertIsArray($row->warnings);
        $this->assertArrayHasKey(0, $row->warnings);
        $this->assertSame('balance_not_posted', $row->warnings[0]['code']);
        $this->assertSame('error: batch_conflict', $row->data['_results']['ar_balance']);
    }

    public function test_finalize_import_retry_does_not_duplicate_posted_documents(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['name' => 'Acme Corp', 'type' => 'customer', 'code' => 'CUST-001', 'opening_balance' => '100'],
        ]);

        $this->importService->executeImport($job);
        $this->assertSame(1, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());

        $this->importService->finalizeImport($job->refresh(), $this->company->id);

        $this->assertSame(1, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());
    }

    public function test_locked_opening_balances_make_balance_rows_invalid_at_validation(): void
    {
        OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::ArOpenItems,
            'name' => 'Locked AR',
            'cutover_date' => now(),
            'status' => OpeningBatchStatus::Locked,
            'source_system' => 'manual',
            'created_by' => $this->user->id,
            'locked_by' => $this->user->id,
            'locked_at' => now(),
        ]);

        $job = $this->makeValidatedJob([
            1 => ['name' => 'Acme Corp', 'type' => 'customer', 'code' => 'CUST-001', 'opening_balance' => '100'],
            2 => ['name' => 'Partner Only', 'type' => 'customer', 'code' => 'NO-BAL'],
        ]);

        $this->assertSame(1, $job->refresh()->failed_rows);
        $balanceRow = $job->rows()->where('row_number', 1)->firstOrFail()->refresh();
        $partnerOnlyRow = $job->rows()->where('row_number', 2)->firstOrFail()->refresh();

        $this->assertFalse($balanceRow->is_valid);
        $this->assertSame(['Opening balances are locked for this company.'], $balanceRow->errors['opening_balance'] ?? null);
        $this->assertTrue($partnerOnlyRow->is_valid);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function makeValidatedJob(array $rows): ImportJob
    {
        $job = $this->importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::Parties,
            filename: 'parties.csv',
            filePath: 'imports/parties.csv',
            totalRows: count($rows),
        );
        $this->importService->addRowsBatch($job, $rows);
        $this->importService->validateJob($job->refresh());

        return $job->refresh();
    }
}
