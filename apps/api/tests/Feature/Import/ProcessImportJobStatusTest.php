<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\EnrichImportedProductsJob;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Application\Services\ImportEnrichmentDispatcher;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Behavioral parity between the async ProcessImportJob and the synchronous
 * ImportService::executeImport path: partial success must complete (not
 * fail), and failed_rows must include validation-skipped rows.
 */
class ProcessImportJobStatusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

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
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
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
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
    }

    public function test_finalize_exception_preserves_imported_counters_and_marks_partial_completion(): void
    {
        $job = $this->seedJob([['data' => ['name' => 'Imported'], 'is_valid' => true]]);
        $job->rows()->update(['is_imported' => true, 'outcome' => ImportRowOutcome::Imported->value]);
        $job->update(['status' => ImportStatus::Importing]);
        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))->failed(new \RuntimeException('Finalize failed'));
        $this->assertSame('partially_completed', $job->refresh()->status->value);
        $this->assertSame(1, $job->successful_rows);
        $this->assertSame(0, $job->failed_rows);
        $this->assertStringContainsString('Finalize failed', $job->error_message);
        $this->assertSame(ImportErrorCode::InternalError, $job->error_code);
    }

    /**
     * @param  array<int, array{data: array<string, mixed>, is_valid: bool}>  $rows
     */
    private function seedJob(array $rows): ImportJob
    {
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);
        $validCount = count(array_filter($rows, fn (array $r): bool => $r['is_valid']));

        // Mirror the durable outcome equations left by validation.
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => 'imports/products.csv',
            'total_rows' => count($rows),
            'processed_rows' => count($rows) - $validCount,
            'successful_rows' => 0,
            'failed_rows' => count($rows) - $validCount,
        ]);

        foreach ($rows as $i => $row) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $i + 1,
                'data' => $row['data'],
                'is_valid' => $row['is_valid'],
                'outcome' => $row['is_valid'] ? ImportRowOutcome::Pending : ImportRowOutcome::Failed,
                'import_error_code' => $row['is_valid'] ? null : ImportErrorCode::ValidationFailed,
            ]);
        }

        return $job;
    }

    private function runJob(ImportJob $job): void
    {
        $this->assertTrue(
            $this->app->make(ImportJobClaimService::class)->claim($job)->won,
            'The controller-owned claim fixture must win before the worker is delivered.',
        );

        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))
            ->handle(
                $this->app->make(ImportService::class),
                $this->app->make(UnitsProvisioningService::class),
                null,
                $this->app->make(ImportEnrichmentDispatcher::class),
            );
    }

    /**
     * @return array{data: array<string, mixed>, is_valid: bool}
     */
    private function validProductRow(string $sku): array
    {
        return [
            'data' => [
                'name' => 'Product '.$sku,
                'sku' => $sku,
                'type' => 'part',
                'sale_price' => '10.00',
                'purchase_price' => '5.00',
            ],
            'is_valid' => true,
        ];
    }

    public function test_partial_execution_failure_completes_instead_of_failing_whole_job(): void
    {
        $job = $this->seedJob([
            $this->validProductRow('OK-1'),
            $this->validProductRow('OK-2'),
            // Passed validation but will fail at execution (no name → DB constraint)
            ['data' => ['sku' => 'EXEC-FAIL-1'], 'is_valid' => true],
        ]);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ImportStatus::PartiallyCompleted, $job->status, 'Partial success must not mark the whole job failed');
        $this->assertSame(2, $job->successful_rows);
        $this->assertSame(1, $job->failed_rows);
    }

    public function test_failed_rows_includes_validation_skipped_rows(): void
    {
        $job = $this->seedJob([
            $this->validProductRow('OK-3'),
            // Failed validation — skipped at execution, but must still count as failed
            ['data' => ['sku' => 'INVALID-1'], 'is_valid' => false],
        ]);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ImportStatus::PartiallyCompleted, $job->status);
        $this->assertSame(1, $job->successful_rows);
        $this->assertSame(1, $job->failed_rows, 'failed_rows must include validation-skipped rows (sync-path parity)');
    }

    public function test_zero_successes_marks_job_failed(): void
    {
        $job = $this->seedJob([
            ['data' => ['sku' => 'EXEC-FAIL-2'], 'is_valid' => true],
            ['data' => ['sku' => 'EXEC-FAIL-3'], 'is_valid' => true],
        ]);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Failed, $job->status);
        $this->assertSame(0, $job->successful_rows);
        $this->assertSame(2, $job->failed_rows);
    }

    public function test_queue_persists_duplicate_skips_separately_from_failures(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'QUEUE-SKIP',
        ]);
        $job = $this->seedJob([
            $this->validProductRow('QUEUE-SKIP'),
            $this->validProductRow('QUEUE-NEW'),
        ]);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);
        $this->assertSame(2, $job->processed_rows);
        $this->assertSame(1, $job->successful_rows);
        $this->assertSame(1, $job->skipped_rows);
        $this->assertSame(0, $job->failed_rows);
    }

    public function test_async_worker_dispatches_enrichment_after_it_wins_terminal_finalization(): void
    {
        Queue::fake();
        $rows = [];
        for ($index = 1; $index <= 100; $index++) {
            $rows[] = $this->validProductRow(sprintf('ASYNC-ENRICH-%03d', $index));
        }
        $job = $this->seedJob($rows);
        $job->update(['options' => ['enrichment_enabled' => true]]);

        $this->runJob($job->refresh());

        $this->assertSame(ImportStatus::Completed, $job->refresh()->status);
        Queue::assertPushedOn('enrichment', EnrichImportedProductsJob::class, function (EnrichImportedProductsJob $queued) use ($job): bool {
            return $queued->importJobId === $job->id
                && $queued->companyId === $this->company->id
                && $queued->tenantId === $this->tenant->id;
        });
    }

    public function test_parties_job_posts_ar_opening_batch_after_async_row_loop(): void
    {
        $importService = $this->app->make(ImportService::class);
        $job = $importService->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::Parties,
            filename: 'parties.csv',
            filePath: 'imports/parties.csv',
            totalRows: 1,
        );
        $importService->addRowsBatch($job, [
            1 => ['name' => 'Async Customer', 'type' => 'customer', 'code' => 'ASYNC-CUST', 'opening_balance' => '120'],
        ]);
        $importService->validateJob($job->refresh());

        // Rule 20: a queued worker runs with NO CompanyContext. setUp() binds one
        // for the controller-side fixture work above, so clear it here — leaving it
        // bound is what let the staging currency-scale defect through a green suite.
        app(CompanyContext::class)->clear();

        $this->runJob($job->refresh());

        $this->assertSame(1, OpeningBalanceBatch::where('type', OpeningBatchType::ArOpenItems)->count());
        $this->assertSame(1, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());
    }
}
