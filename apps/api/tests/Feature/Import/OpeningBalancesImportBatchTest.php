<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
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

    /**
     * Requirement 4/5: an unmappable account is actionable per-row feedback, never a
     * thrown/aborted run. The job's terminal LABEL is Failed because nothing posted —
     * a green Completed on an import that changed nothing is the failure mode rule 20
     * exists to prevent.
     */
    public function test_unknown_account_becomes_an_invalid_row_without_aborting_the_run(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '999999', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        // Must not throw — the whole point of requirement 4.
        $result = $this->importService->executeImport($job);
        $this->assertSame(0, $result['imported_count']);

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);

        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $warnings = $row->warnings ?? [];
        $this->assertCount(1, $warnings);
        $this->assertSame('balance_not_posted', $warnings[0]['code']);
        $this->assertStringContainsString('999999', $warnings[0]['detail']);
        $this->assertSame('error: validation_failed', $row->data['_results']['gl_balance'] ?? null);
        $this->assertNull($row->imported_entity_id);
        $this->assertFalse($row->is_imported, 'a row that did not post must not count as imported');

        // Nothing posted => no batch residue that would block the next import or
        // the accountant's opening-balance wizard.
        $this->assertSame(0, OpeningBalanceBatch::forCompany($this->company->id)->count());
        $this->assertSame(0, $job->refresh()->successful_rows);
    }

    /**
     * ALL-OR-NOTHING: an opening balance is entered once and locked forever, so a
     * file that cannot be mapped in full must post NOTHING. Posting the mappable
     * subset would lock an understated opening equity that neither the import nor
     * the UI wizard can ever correct.
     */
    public function test_a_single_invalid_row_blocks_the_whole_file_from_posting(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
            2 => ['account_code' => '999999', 'debit' => '100.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);

        $this->assertSame(
            0,
            JournalEntry::where('company_id', $this->company->id)->count(),
            'no partial opening balance may be posted'
        );
        $this->assertSame(0, OpeningBalanceBatch::forCompany($this->company->id)->count());

        $goodRow = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('balance_not_posted', ($goodRow->warnings ?? [])[0]['code'] ?? null);
        $this->assertSame('error: file_not_posted', $goodRow->data['_results']['gl_balance'] ?? null);
        $this->assertNull($goodRow->imported_entity_id);
        $this->assertFalse($goodRow->is_imported);

        $badRow = $job->rows()->where('row_number', 2)->firstOrFail();
        $this->assertSame('error: validation_failed', $badRow->data['_results']['gl_balance'] ?? null);
        $this->assertStringContainsString('999999', ($badRow->warnings ?? [])[0]['detail'] ?? '');
    }

    /**
     * The all-or-nothing guard must be FILE-scoped, not batch-scoped. A row the
     * IMPORT's own ruleset rejects (blank account_code, or the 3-decimal money
     * ceiling) never reaches the opening batch at all — the row loop only runs for
     * is_valid rows — so a guard that only inspects batch rows cannot see it and the
     * remaining subset would post and lock an understated opening equity.
     *
     * @dataProvider ingressRejectedRowProvider
     *
     * @param  array<string, string>  $badRow
     */
    public function test_a_row_rejected_at_ingress_blocks_the_whole_file(array $badRow): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
            2 => $badRow,
        ]);

        $this->assertFalse(
            $job->rows()->where('row_number', 2)->firstOrFail()->is_valid,
            'the fixture must actually be rejected at ingress'
        );

        $this->importService->executeImport($job);

        $this->assertSame(
            0,
            JournalEntry::where('company_id', $this->company->id)->count(),
            'no partial opening balance may be posted'
        );
        $this->assertSame(0, OpeningBalanceBatch::forCompany($this->company->id)->count());

        $job->refresh();
        $this->assertSame(0, $job->successful_rows);
        $this->assertSame(ImportStatus::Failed, $job->status, 'an import that posted nothing has not succeeded');

        $goodRow = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('error: file_not_posted', $goodRow->data['_results']['gl_balance'] ?? null);
        $this->assertSame('balance_not_posted', ($goodRow->warnings ?? [])[0]['code'] ?? null);
        $this->assertFalse($goodRow->is_imported);
        $this->assertNull($goodRow->imported_entity_id);

        // …and the corrected file still imports cleanly afterwards.
        $corrected = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);
        $this->importService->executeImport($corrected);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('opening_balance', $entry->source_type);
        $this->assertSame(ImportStatus::Completed, $corrected->refresh()->status);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function ingressRejectedRowProvider(): array
    {
        return [
            'blank account code' => [['account_code' => '', 'debit' => '100.00', 'credit' => '0.00']],
            'money beyond three decimals' => [['account_code' => '512000', 'debit' => '100.1234', 'credit' => '0.00']],
        ];
    }

    /**
     * Staging canonicalizes money at STORAGE scale 3 and journal_lines.debit/credit
     * are decimal(15,3). Computing the opening entry at the currency's DISPLAY scale
     * (EUR -> 2) would truncate the 3rd decimal into the OBE plug — i.e. silently
     * into equity.
     */
    public function test_the_third_decimal_survives_for_a_two_decimal_display_currency(): void
    {
        $this->assertSame('EUR', $this->company->currency);

        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '100.125', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $lines = $entry->lines()->get();

        $cashLine = $lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertNotNull($cashLine);
        $this->assertSame(0, bccomp($cashLine->debit, '100.125', 3), 'the 3rd decimal must not be truncated');

        $obeLine = $lines->firstWhere('account_id', $this->obeAccount->id);
        $this->assertNotNull($obeLine);
        $this->assertSame(0, bccomp($obeLine->credit, '100.125', 3), 'the OBE plug must not absorb a truncated remainder');
    }

    public function test_the_corrected_file_can_be_re_imported_after_a_blocked_import(): void
    {
        $blocked = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
            2 => ['account_code' => '999999', 'debit' => '100.00', 'credit' => '0.00'],
        ]);
        $this->importService->executeImport($blocked);
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $corrected = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5100.00', 'credit' => '0.00'],
        ]);
        $this->importService->executeImport($corrected);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('opening_balance', $entry->source_type);

        $cashLine = $entry->lines()->where('account_id', $this->cashAccount->id)->firstOrFail();
        $this->assertSame(0, bccomp($cashLine->debit, '5100', 3));
    }

    /**
     * CLAUDE.md rule 20: the queued worker binds TENANT context only — no
     * CompanyContext — and imports of >= ImportController::ASYNC_THRESHOLD rows
     * always take that path. Scale resolution must therefore never depend on a
     * bound company.
     */
    public function test_queued_worker_posts_opening_balances_with_no_bound_company_context(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        app(CompanyContext::class)->clear();

        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))
            ->handle(app(ImportService::class));

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);

        $entry = JournalEntry::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('opening_balance', $entry->source_type);
        $this->assertTrue($entry->is_historical);

        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);
        $cashLine = $lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertNotNull($cashLine);
        $this->assertSame(0, bccomp($cashLine->debit, '5000', 3));

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('ok', $row->data['_results']['gl_balance'] ?? null);
        $this->assertSame($entry->id, $row->imported_entity_id);
    }

    public function test_re_running_finalize_does_not_double_post_the_opening_entry(): void
    {
        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);
        $entryId = JournalEntry::where('company_id', $this->company->id)->value('id');

        $this->importService->finalizeImport($job->refresh(), $this->company->id);

        $this->assertSame(1, JournalEntry::where('company_id', $this->company->id)->count());
        $this->assertSame(
            1,
            OpeningBalanceBatch::forCompany($this->company->id)->ofType(OpeningBatchType::Accounting)->count()
        );

        // A redelivery must not wipe the linkage it reports as 'ok'.
        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('ok', $row->data['_results']['gl_balance'] ?? null);
        $this->assertSame($entryId, $row->imported_entity_id);
        $this->assertTrue($row->is_imported);
    }

    public function test_missing_opening_balance_equity_account_warns_instead_of_failing_the_job(): void
    {
        $this->obeAccount->delete();

        $job = $this->makeValidatedJob([
            1 => ['account_code' => '512000', 'debit' => '5000.00', 'credit' => '0.00'],
        ]);

        $this->importService->executeImport($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());

        $row = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame('balance_not_posted', ($row->warnings ?? [])[0]['code'] ?? null);
        $this->assertSame('error: post_failed', $row->data['_results']['gl_balance'] ?? null);
        $this->assertSame(0, OpeningBalanceBatch::forCompany($this->company->id)->count());
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
