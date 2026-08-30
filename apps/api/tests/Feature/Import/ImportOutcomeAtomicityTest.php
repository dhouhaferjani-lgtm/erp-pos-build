<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Shared\Contracts\PartnerServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ImportOutcomeAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->user = User::factory()->for($this->tenant)->create();
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_validation_failures_are_terminal_and_job_equations_hold_before_and_after_execute(): void
    {
        $allInvalid = $this->job(ImportType::Partners, 1);
        app(ImportService::class)->addRowsBatch($allInvalid, [1 => ['name' => '', 'type' => 'customer']]);
        app(ImportService::class)->validateJob($allInvalid);

        $invalidRow = $allInvalid->rows()->firstOrFail();
        $this->assertSame(ImportStatus::Failed, $allInvalid->refresh()->status);
        $this->assertSame(ImportRowOutcome::Failed, $invalidRow->outcome);
        $this->assertSame(ImportErrorCode::ValidationFailed, $invalidRow->import_error_code);
        $this->assertSame(1, $allInvalid->processed_rows);
        $this->assertSame(1, $allInvalid->failed_rows);

        $mixed = $this->job(ImportType::Partners, 2);
        app(ImportService::class)->addRowsBatch($mixed, [
            1 => ['name' => 'Valid partner', 'type' => 'customer'],
            2 => ['name' => '', 'type' => 'customer'],
        ]);
        app(ImportService::class)->validateJob($mixed);

        $this->assertSame(1, $mixed->refresh()->processed_rows);
        $this->assertSame(0, $mixed->successful_rows);
        $this->assertSame(1, $mixed->failed_rows);

        app(ImportService::class)->executeImport($mixed);
        $mixed->refresh();
        $this->assertSame(2, $mixed->processed_rows);
        $this->assertSame(1, $mixed->successful_rows);
        $this->assertSame(1, $mixed->failed_rows);
        $this->assertSame($mixed->total_rows, $mixed->successful_rows + $mixed->failed_rows);
        $this->assertCount(0, app(ImportService::class)->getValidRows($mixed));
    }

    public function test_committed_entity_and_correction_warning_are_not_selected_or_applied_twice(): void
    {
        $job = $this->pendingPartnerJob(['name' => 'Atomic partner', 'type' => 'customer']);
        $row = $job->rows()->firstOrFail();
        $row->update(['warnings' => [[
            'code' => ImportWarningCode::OpeningCorrected->value,
            'detail' => 'Opening correction committed.',
        ]]]);

        $outcome = app(ImportService::class)->processPendingRow($job, $row);

        $this->assertSame(ImportRowOutcome::Imported, $outcome);
        $this->assertSame(1, Partner::query()->where('company_id', $this->company->id)->count());
        $this->assertCount(0, app(ImportService::class)->getValidRows($job));
        $warnings = $row->refresh()->warnings;
        $this->assertNotNull($warnings);
        $this->assertCount(1, $warnings);
        $this->assertSame(ImportWarningCode::OpeningCorrected->value, $warnings[0]['code']);
    }

    public function test_skip_decision_commits_with_no_new_entity_and_is_not_retried(): void
    {
        $existing = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SKIP-ME',
        ]);
        $job = $this->job(ImportType::Products, 1);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);
        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Changed name', 'sku' => 'SKIP-ME', '_provided' => ['name', 'sku']],
            'is_valid' => true,
            'duplicate_bucket' => DuplicateBucket::ExistingSku,
        ]);

        $outcome = app(ImportService::class)->processPendingRow($job, $row);

        $this->assertSame(ImportRowOutcome::DuplicateSkipped, $outcome);
        $this->assertFalse($row->refresh()->is_imported);
        $this->assertSame($existing->name, $existing->refresh()->name);
        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->count());
        $this->assertCount(0, app(ImportService::class)->getValidRows($job));
    }

    public function test_sync_finalization_persists_duplicate_skips_separately(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SYNC-SKIP',
        ]);
        $job = $this->job(ImportType::Products, 1);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'Skip sync', 'sku' => 'SYNC-SKIP', '_provided' => ['name', 'sku']],
            'is_valid' => true,
        ]);

        $result = app(ImportService::class)->executeImport($job->refresh());

        $job->refresh();
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(1, $job->processed_rows);
        $this->assertSame(0, $job->successful_rows);
        $this->assertSame(1, $job->skipped_rows);
        $this->assertSame(0, $job->failed_rows);
        $this->assertSame(ImportStatus::Completed, $job->status);
    }

    public function test_throw_inside_row_transaction_rolls_back_entity_then_writes_failed_separately(): void
    {
        $this->app->bind(PartnerServiceInterface::class, fn (): PartnerServiceInterface => new class implements PartnerServiceInterface
        {
            public function findByVatOrName(string $tenantId, string $companyId, ?string $vatNumber, string $name): ?array
            {
                return null;
            }

            public function upsertWithTypeMerge(string $tenantId, string $companyId, array $data): string
            {
                Partner::factory()->create(['tenant_id' => $tenantId, 'company_id' => $companyId]);
                throw new RuntimeException('fault injected inside row transaction');
            }
        });
        $this->app->forgetInstance(ImportService::class);
        $failureUpdates = [];
        DB::listen(function ($query) use (&$failureUpdates): void {
            if (str_contains($query->sql, 'update "import_rows"') && str_contains($query->sql, '"outcome"')) {
                $failureUpdates[] = $query->sql;
            }
        });

        $job = $this->pendingPartnerJob(['name' => 'Rollback partner', 'type' => 'customer']);
        $row = $job->rows()->firstOrFail();
        $outcome = app(ImportService::class)->processPendingRow($job, $row);

        $this->assertSame(ImportRowOutcome::Failed, $outcome);
        $this->assertSame(0, Partner::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(ImportErrorCode::InternalError, $row->refresh()->import_error_code);
        $this->assertCount(1, $failureUpdates);
        $this->assertStringContainsString('"import_error_code"', $failureUpdates[0]);
    }

    public function test_resume_after_committed_ordinary_write_does_not_write_the_entity_twice(): void
    {
        $job = $this->partnerJobWithRows([
            1 => ['name' => 'First committed', 'type' => 'customer'],
            2 => ['name' => 'Second pending', 'type' => 'customer'],
        ]);

        $this->assertFaultAfterFirstCommittedRow($job);
        $this->runPendingRows($job);

        $this->assertSame(2, Partner::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(
            [ImportRowOutcome::Imported, ImportRowOutcome::Imported],
            $job->rows()->orderBy('row_number')->pluck('outcome')->all(),
        );
    }

    public function test_resume_after_committed_duplicate_skip_keeps_the_no_op_terminal(): void
    {
        foreach (['SKIP-1', 'SKIP-2'] as $sku) {
            Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => $sku,
            ]);
        }
        $job = $this->job(ImportType::Products, 2);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);
        foreach ([1 => 'SKIP-1', 2 => 'SKIP-2'] as $number => $sku) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $number,
                'data' => ['name' => 'Skip '.$number, 'sku' => $sku, '_provided' => ['name', 'sku']],
                'is_valid' => true,
            ]);
        }

        $this->assertFaultAfterFirstCommittedRow($job);
        $this->runPendingRows($job);

        $this->assertSame(2, Product::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(
            [ImportRowOutcome::DuplicateSkipped, ImportRowOutcome::DuplicateSkipped],
            $job->rows()->orderBy('row_number')->pluck('outcome')->all(),
        );
    }

    public function test_resume_keeps_a_committed_opening_correction_warning(): void
    {
        $job = $this->partnerJobWithRows([
            1 => ['name' => 'Corrected opening owner', 'type' => 'customer'],
            2 => ['name' => 'Second partner', 'type' => 'customer'],
        ]);
        $runner = new CommittedRowFaultDecorator(
            app(ImportService::class),
            function (ImportRow $row): void {
                app(ImportService::class)->addRowWarning(
                    $row,
                    ImportWarningCode::OpeningCorrected,
                    'Opening correction committed.',
                );
            },
        );
        $this->app->instance(CommittedRowFaultDecorator::class, $runner);

        try {
            app(CommittedRowFaultDecorator::class)->run($job, 1);
            $this->fail('The bound post-commit decorator must abort before row two starts.');
        } catch (RuntimeException $exception) {
            $this->assertSame(CommittedRowFaultDecorator::MESSAGE, $exception->getMessage());
        }
        $this->runPendingRows($job);

        $first = $job->rows()->where('row_number', 1)->firstOrFail();
        $this->assertSame(ImportRowOutcome::Imported, $first->outcome);
        $this->assertContains(
            ImportWarningCode::OpeningCorrected->value,
            array_column($first->warnings ?? [], 'code'),
        );
        $this->assertSame(2, Partner::query()->where('company_id', $this->company->id)->count());
    }

    public function test_resume_after_post_rollback_failed_row_preserves_failure_and_moves_to_next_row(): void
    {
        $this->app->bind(PartnerServiceInterface::class, fn (): PartnerServiceInterface => new class implements PartnerServiceInterface
        {
            public function findByVatOrName(string $tenantId, string $companyId, ?string $vatNumber, string $name): ?array
            {
                return null;
            }

            public function upsertWithTypeMerge(string $tenantId, string $companyId, array $data): string
            {
                if ($data['name'] === 'Rollback first') {
                    Partner::factory()->create(['tenant_id' => $tenantId, 'company_id' => $companyId]);
                    throw new RuntimeException('fault inside row transaction');
                }

                return Partner::factory()->create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'name' => $data['name'],
                ])->id;
            }
        });
        $this->app->forgetInstance(ImportService::class);
        $job = $this->partnerJobWithRows([
            1 => ['name' => 'Rollback first', 'type' => 'customer'],
            2 => ['name' => 'Commit second', 'type' => 'customer'],
        ]);

        $this->assertFaultAfterFirstCommittedRow($job);
        $this->runPendingRows($job);

        $rows = $job->rows()->orderBy('row_number')->get();
        $first = $rows->get(0);
        $second = $rows->get(1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(ImportRowOutcome::Failed, $first->outcome);
        $this->assertSame(ImportErrorCode::InternalError, $first->import_error_code);
        $this->assertSame(ImportRowOutcome::Imported, $second->outcome);
        $this->assertSame(1, Partner::query()->where('company_id', $this->company->id)->count());
    }

    public function test_sparse_product_re_import_preserves_unit_type_and_prices(): void
    {
        $category = UnitCategory::factory()->create(['name' => 'Pieces']);
        $unit = Unit::factory()->for($category, 'category')->create(['code' => 'box']);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sparse product',
            'sku' => 'SPARSE-P',
            'type' => ProductType::Service,
            'unit' => 'box',
            'unit_id' => $unit->id,
            'sale_price' => '17.250',
            'purchase_price' => '9.750',
        ]);
        $job = $this->job(ImportType::Products, 1);
        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => [
                'name' => 'Sparse product',
                'sku' => 'SPARSE-P',
                '_provided' => ['name', 'sku'],
            ],
            'is_valid' => true,
        ]);

        $this->assertSame(ImportRowOutcome::Imported, app(ImportService::class)->processPendingRow($job, $row));

        $product->refresh();
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertSame('box', $product->unit);
        $this->assertSame(ProductType::Service, $product->type);
        $this->assertSame('17.250', $product->sale_price);
        $this->assertSame('9.750', $product->purchase_price);
    }

    public function test_sparse_partner_re_import_preserves_contact_and_address_fields(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sparse partner',
            'type' => 'customer',
            'email' => 'saved@example.test',
            'phone' => '+21670000000',
            'street_address' => '10 Existing Street',
            'city' => 'Tunis',
            'postal_code' => '1000',
        ]);
        $job = $this->job(ImportType::Partners, 1);
        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => [
                'name' => 'Sparse partner',
                'type' => 'customer',
                '_provided' => ['name', 'type'],
            ],
            'is_valid' => true,
        ]);

        $this->assertSame(ImportRowOutcome::Imported, app(ImportService::class)->processPendingRow($job, $row));

        $partner->refresh();
        $this->assertSame('saved@example.test', $partner->email);
        $this->assertSame('+21670000000', $partner->phone);
        $this->assertSame('10 Existing Street', $partner->street_address);
        $this->assertSame('Tunis', $partner->city);
        $this->assertSame('1000', $partner->postal_code);
    }

    private function assertFaultAfterFirstCommittedRow(ImportJob $job): void
    {
        $this->app->instance(
            CommittedRowFaultDecorator::class,
            new CommittedRowFaultDecorator(app(ImportService::class)),
        );

        try {
            app(CommittedRowFaultDecorator::class)->run($job, 1);
            $this->fail('The bound post-commit decorator must abort before row two starts.');
        } catch (RuntimeException $exception) {
            $this->assertSame(CommittedRowFaultDecorator::MESSAGE, $exception->getMessage());
        }

        $this->assertSame(1, $job->rows()->where('outcome', '!=', ImportRowOutcome::Pending)->count());
    }

    private function runPendingRows(ImportJob $job): void
    {
        $service = app(ImportService::class);
        foreach ($service->getValidRows($job->refresh()) as $row) {
            $service->processPendingRow($job, $row);
        }
    }

    /** @param array<int, array<string, string>> $rows */
    private function partnerJobWithRows(array $rows): ImportJob
    {
        $job = $this->job(ImportType::Partners, count($rows));
        foreach ($rows as $number => $data) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $number,
                'data' => $data,
                'is_valid' => true,
            ]);
        }

        return $job;
    }

    /** @param array<string, string> $data */
    private function pendingPartnerJob(array $data): ImportJob
    {
        $job = $this->job(ImportType::Partners, 1);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => $data,
            'is_valid' => true,
        ]);

        return $job;
    }

    private function job(ImportType $type, int $totalRows): ImportJob
    {
        return app(ImportService::class)->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: $type,
            filename: 'atomic.csv',
            filePath: 'imports/atomic.csv',
            totalRows: $totalRows,
        );
    }
}

final class CommittedRowFaultDecorator
{
    public const string MESSAGE = 'fault injected after committed row and before next row';

    /** @param (callable(ImportRow): void)|null $afterCommit */
    public function __construct(
        private readonly ImportService $service,
        private readonly mixed $afterCommit = null,
    ) {}

    public function run(ImportJob $job, int $throwAfter): void
    {
        $processed = 0;
        foreach ($this->service->getValidRows($job->refresh()) as $row) {
            $this->service->processPendingRow($job, $row);
            $processed++;
            if ($processed === $throwAfter) {
                if (is_callable($this->afterCommit)) {
                    ($this->afterCommit)($row->refresh());
                }
                throw new RuntimeException(self::MESSAGE);
            }
        }
    }
}
