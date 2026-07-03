<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    /**
     * @param  array<int, array{data: array<string, mixed>, is_valid: bool}>  $rows
     */
    private function seedJob(array $rows): ImportJob
    {
        $validCount = count(array_filter($rows, fn (array $r): bool => $r['is_valid']));

        // Mirror the state ImportController@store leaves behind after
        // validation: successful_rows = valid count, failed_rows = invalid.
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => 'imports/products.csv',
            'total_rows' => count($rows),
            'processed_rows' => 0,
            'successful_rows' => $validCount,
            'failed_rows' => count($rows) - $validCount,
        ]);

        foreach ($rows as $i => $row) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $i + 1,
                'data' => $row['data'],
                'is_valid' => $row['is_valid'],
            ]);
        }

        return $job;
    }

    private function runJob(ImportJob $job): void
    {
        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))
            ->handle($this->app->make(ImportService::class));
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
        $this->assertSame(ImportStatus::Completed, $job->status, 'Partial success must not mark the whole job failed');
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
        $this->assertSame(ImportStatus::Completed, $job->status);
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

    public function test_parties_job_posts_ar_opening_batch_after_async_row_loop(): void
    {
        $importService = $this->app->make(ImportService::class);
        $job = $importService->createJob(
            tenantId: $this->tenant->id,
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

        $this->runJob($job->refresh());

        $this->assertSame(1, OpeningBalanceBatch::where('type', OpeningBatchType::ArOpenItems)->count());
        $this->assertSame(1, Document::where('company_id', $this->company->id)->where('is_historical', true)->count());
    }
}
