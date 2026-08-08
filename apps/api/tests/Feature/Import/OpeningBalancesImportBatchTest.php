<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GL opening-balance import must flow through the batch-documented path
 * (AccountingOpeningService) — every posted journal entry carries
 * source_type/source_id back to an OpeningBalanceBatch, is flagged historical,
 * and is balanced (OBE plug when the CSV itself is one-sided).
 */
final class OpeningBalancesImportBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private ImportService $importService;

    private Account $cashAccount;

    private Account $obeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-gl-opening-import',
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
            'fiscal_year_start_month' => 4,
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import Admin',
            'email' => 'gl-import-admin@example.com',
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

        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '512000',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->obeAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '890000',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity->value,
            'is_system' => true,
        ]);

        $this->importService = app(ImportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_opening_balance_import_posts_a_batch_sourced_historical_entry(): void
    {
        $job = $this->makeValidatedJob([
            1 => [
                'account_code' => '512000',
                'debit' => '5000.00',
                'credit' => '0.00',
                'description' => 'Opening cash balance',
                'reference' => 'LEG-1',
            ],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);

        $batch = $this->importBatch($job);
        $this->assertNotNull($batch);
        $this->assertSame(OpeningBatchType::Accounting, $batch->type);
        $this->assertSame(OpeningBatchStatus::Locked, $batch->status);
        $this->assertSame('unified-import', $batch->source_system);

        $entries = JournalEntry::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $entries);

        $entry = $entries->first();
        $this->assertNotNull($entry);
        $this->assertSame('opening_balance', $entry->source_type);
        $this->assertSame($batch->id, $entry->source_id);
        $this->assertTrue($entry->is_historical);
        // Fiscal-year-start fallback: FY starts in month 4, "today" is 2026-06-15.
        $this->assertSame('2026-04-01', $entry->entry_date->toDateString());

        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines, 'one CSV line + the OBE plug');

        $cashLine = $lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertNotNull($cashLine);
        $this->assertSame(0, bccomp($cashLine->debit, '5000', 3));
        $this->assertStringContainsString('Opening cash balance', (string) $cashLine->description);
        $this->assertStringContainsString('LEG-1', (string) $cashLine->description);

        $obeLine = $lines->firstWhere('account_id', $this->obeAccount->id);
        $this->assertNotNull($obeLine);
        $this->assertSame(0, bccomp($obeLine->credit, '5000', 3));

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, 3);
            $totalCredit = bcadd($totalCredit, $line->credit, 3);
        }
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3), 'posted entry must be balanced');

        $this->assertSame(
            0,
            JournalEntry::where('company_id', $this->company->id)->whereNull('source_type')->count(),
            'the import must not leave unsourced journal entries behind'
        );

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame($entry->id, $row->imported_entity_id);
        $this->assertSame('ok', $row->data['_results']['gl_balance'] ?? null);
    }

    public function test_balanced_opening_balance_import_posts_without_an_obe_plug(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '101000',
            'name' => 'Capital',
            'type' => AccountType::Equity,
        ]);

        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
            2 => ['account_code' => '101000', 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        $this->importService->executeImport($job);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $lines = $entry->lines()->get();

        $this->assertCount(2, $lines);
        $this->assertNull($lines->firstWhere('account_id', $this->obeAccount->id));
    }

    public function test_unknown_account_becomes_an_invalid_row_not_a_job_failure(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '999999', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertNotSame(ImportStatus::Failed, $job->status);
        $this->assertSame(ImportStatus::Completed, $job->status);

        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $warnings = $row->warnings ?? [];
        $this->assertCount(1, $warnings);
        $this->assertSame('balance_not_posted', $warnings[0]['code']);
        $this->assertStringContainsString('999999', $warnings[0]['detail']);
        $this->assertSame('error: validation_failed', $row->data['_results']['gl_balance'] ?? null);
        $this->assertNull($row->imported_entity_id);

        $batch = $this->importBatch($job);
        $this->assertNotNull($batch);
        $this->assertSame(OpeningBatchStatus::Draft, $batch->status);
        $this->assertSame(1, $batch->rows()->where('status', OpeningImportRowStatus::Skipped)->count());
    }

    public function test_valid_rows_post_while_the_unknown_account_row_is_skipped(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
            2 => ['account_code' => '999999', 'debit' => '100.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);

        $cashLine = $lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertNotNull($cashLine);
        $this->assertSame(0, bccomp($cashLine->debit, '5000', 3));

        $skipped = $job->rows()->where('row_number', 2)->firstOrFail();
        $this->assertSame('balance_not_posted', ($skipped->warnings ?? [])[0]['code'] ?? null);
        $this->assertNull($skipped->imported_entity_id);
    }

    public function test_re_running_finalize_does_not_double_post_the_opening_entry(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);
        $this->importService->finalizeImport($job->refresh(), $this->company->id);

        $this->assertSame(1, JournalEntry::where('company_id', $this->company->id)->count());
        $this->assertSame(
            1,
            OpeningBalanceBatch::forCompany($this->company->id)->ofType(OpeningBatchType::Accounting)->count()
        );
    }

    public function test_missing_opening_balance_equity_account_warns_instead_of_failing_the_job(): void
    {
        $this->obeAccount->delete();

        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('balance_not_posted', ($row->warnings ?? [])[0]['code'] ?? null);
        $this->assertSame('error: post_failed', $row->data['_results']['gl_balance'] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function makeValidatedJob(array $rows): ImportJob
    {
        $job = $this->importService->createJob(
            tenantId: $this->tenant->id,
            userId: $this->user->id,
            type: ImportType::OpeningBalances,
            filename: 'balances.csv',
            filePath: 'imports/balances.csv',
            totalRows: count($rows),
        );

        foreach ($rows as $rowNumber => $data) {
            $this->importService->addRow($job, $rowNumber, $data);
        }

        $this->importService->validateJob($job);

        return $job->refresh();
    }

    private function importBatch(ImportJob $job): ?OpeningBalanceBatch
    {
        return OpeningBalanceBatch::forCompany($this->company->id)
            ->ofType(OpeningBatchType::Accounting)
            ->get()
            ->first(fn (OpeningBalanceBatch $batch): bool => ($batch->import_file_reference['import_job_id'] ?? null) === $job->id);
    }
}
