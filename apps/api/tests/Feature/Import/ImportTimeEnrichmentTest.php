<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\EnrichImportedProductsJob;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ResultWorkbookService;
use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Application\Services\CatalogBacklinkDispatcher;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\Contracts\CatalogLookupResultInterface;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\CatalogLookupOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

final class ImportTimeEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Import Enrichment Tenant',
            'slug' => 'import-enrichment-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);
        $this->company = $this->makeCompany('Company A');
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import Enrichment User',
            'email' => 'import-enrichment-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    public function test_found_row_links_by_imported_entity_id_dispatches_apply_and_records_enriched(): void
    {
        Queue::fake();
        $platformProductId = (string) Str::uuid();
        $product = $this->makeProduct($this->company, 'FOUND-1');
        $job = $this->makeCompletedJob($this->company, [
            ['product' => $product, 'barcode' => '3017620422003'],
        ]);
        $lookup = new FakeImportCatalogLookup([
            '3017620422003' => TestCatalogLookupResult::found('3017620422003', $platformProductId),
        ]);
        $companyContext = $this->app->make(CompanyContext::class);
        $companyContext->clear();

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertSame($platformProductId, $product->refresh()->platform_product_id);
        $this->assertSame(['enriched' => 1], $job->refresh()->enrichment_summary);
        $this->assertNull($companyContext->getCompanyId());
        $this->assertSame(['3017620422003'], $lookup->calls);
        Queue::assertPushedOn('enrichment', ApplyCatalogEnrichmentJob::class, function (ApplyCatalogEnrichmentJob $queued) use ($product, $platformProductId): bool {
            return $queued->productId === $product->id
                && $queued->expectedPlatformProductId === $platformProductId
                && $queued->tenantId === $this->tenant->id;
        });
    }

    public function test_job_timeout_finishes_before_horizon_and_redis_can_re_reserve_it(): void
    {
        $job = new EnrichImportedProductsJob(
            importJobId: (string) Str::uuid(),
            tenantId: (string) Str::uuid(),
            companyId: (string) Str::uuid(),
        );

        $horizonTimeout = config('horizon.defaults.supervisor-1.timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertIsInt($horizonTimeout);
        $this->assertIsInt($retryAfter);
        $this->assertLessThan($horizonTimeout, $job->timeout);
        $this->assertLessThan($retryAfter, $horizonTimeout);
    }

    public function test_found_result_with_non_uuid_platform_id_is_warning_only(): void
    {
        Queue::fake();
        $product = $this->makeProduct($this->company, 'BAD-UUID');
        $job = $this->makeCompletedJob($this->company, [
            ['product' => $product, 'barcode' => '4006381333931'],
        ]);
        $lookup = new FakeImportCatalogLookup([
            '4006381333931' => TestCatalogLookupResult::found('4006381333931', 'not-a-uuid'),
        ]);

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertNull($product->refresh()->platform_product_id);
        $row = $job->rows()->sole();
        $this->assertSame('enrichment_unavailable', $row->warnings[0]['code'] ?? null);
        $this->assertStringContainsString('4006381333931', $row->warnings[0]['detail'] ?? '');
        Queue::assertNotPushed(ApplyCatalogEnrichmentJob::class);
    }

    public function test_all_non_found_lookup_outcomes_map_to_their_row_warning_codes(): void
    {
        Queue::fake();
        $cases = [
            ['sku' => 'MISS', 'barcode' => 'MISS-1', 'result' => TestCatalogLookupResult::forOutcome(CatalogLookupOutcome::NotFound), 'warning' => 'enrichment_not_found'],
            ['sku' => 'INVALID', 'barcode' => 'INVALID-1', 'result' => TestCatalogLookupResult::forOutcome(CatalogLookupOutcome::InvalidBarcode), 'warning' => 'enrichment_invalid_barcode'],
            ['sku' => 'ERROR', 'barcode' => 'ERROR-1', 'result' => TestCatalogLookupResult::forOutcome(CatalogLookupOutcome::PlatformError), 'warning' => 'enrichment_unavailable'],
            ['sku' => 'UNAVAILABLE', 'barcode' => 'UNAVAILABLE-1', 'result' => TestCatalogLookupResult::forOutcome(CatalogLookupOutcome::PlatformUnavailable), 'warning' => 'enrichment_unavailable'],
        ];
        $rows = [];
        $results = [];
        foreach ($cases as $case) {
            $product = $this->makeProduct($this->company, $case['sku']);
            $rows[] = ['product' => $product, 'barcode' => $case['barcode']];
            $results[str_replace('-', '', $case['barcode'])] = $case['result'];
        }
        $job = $this->makeCompletedJob($this->company, $rows);

        $this->runEnrichment($job, $this->company, new FakeImportCatalogLookup($results));

        $warnings = $job->rows()->orderBy('row_number')->get()->map(
            static fn (ImportRow $row): ?string => $row->warnings[0]['code'] ?? null,
        )->all();
        $this->assertSame(array_column($cases, 'warning'), $warnings);
        $this->assertSame([
            'enrichment_not_found' => 1,
            'enrichment_unavailable' => 2,
            'enrichment_invalid_barcode' => 1,
        ], $job->refresh()->enrichment_summary);
    }

    public function test_open_circuit_marks_every_remaining_row_unavailable_without_more_calls(): void
    {
        Queue::fake();
        $rows = [];
        foreach (['CIRCUIT-1', 'CIRCUIT-2', 'CIRCUIT-3'] as $sku) {
            $rows[] = ['product' => $this->makeProduct($this->company, $sku), 'barcode' => $sku];
        }
        $job = $this->makeCompletedJob($this->company, $rows);
        $lookup = new FakeImportCatalogLookup(
            ['CIRCUIT1' => TestCatalogLookupResult::forOutcome(CatalogLookupOutcome::PlatformError)],
            circuitOpensAfterCalls: 1,
        );

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertCount(1, $lookup->calls);
        $this->assertSame(3, $job->rows()->whereNotNull('warnings')->count());
        $this->assertSame(['enrichment_unavailable' => 3], $job->refresh()->enrichment_summary);
    }

    public function test_unsupported_vertical_is_one_job_warning_and_zero_row_warnings_or_lookups(): void
    {
        $this->tenant->update(['vertical' => Vertical::Retail]);
        $rows = [
            ['product' => $this->makeProduct($this->company, 'RETAIL-1'), 'barcode' => 'RETAIL-1'],
            ['product' => $this->makeProduct($this->company, 'RETAIL-2'), 'barcode' => 'RETAIL-2'],
        ];
        $job = $this->makeCompletedJob($this->company, $rows);
        $lookup = new FakeImportCatalogLookup([]);

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertSame([], $lookup->calls);
        $this->assertSame(0, $job->rows()->whereNotNull('warnings')->count());
        $this->assertSame(['enrichment_vertical_not_supported' => 1], $job->refresh()->enrichment_summary);
    }

    public function test_distinct_normalized_barcodes_are_capped_at_500_and_repeated_barcodes_lookup_once(): void
    {
        $repeatedProduct = $this->makeProduct($this->company, 'CAP-REPEATED');
        $rows = [
            ['product_id' => $repeatedProduct->id, 'barcode' => 'ABC-1'],
            ['product_id' => $repeatedProduct->id, 'barcode' => 'ABC1'],
        ];
        for ($index = 0; $index < 500; $index++) {
            $product = $this->makeProduct($this->company, sprintf('CAP-%04d', $index));
            $rows[] = [
                'product_id' => $product->id,
                'barcode' => sprintf('CODE%04d', $index),
            ];
        }
        $job = $this->makeCompletedJobFromIds($this->company, $rows);
        $lookup = new FakeImportCatalogLookup([]);

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertCount(500, $lookup->calls);
        $this->assertSame(500, count(array_unique($lookup->calls)));
        $this->assertSame([
            'enrichment_not_found' => 501,
            'enrichment_cap_exceeded' => 1,
        ], $job->refresh()->enrichment_summary);
    }

    public function test_same_payload_re_reserved_after_completion_cannot_lookup_link_or_dispatch_twice(): void
    {
        Queue::fake();
        $platformProductId = (string) Str::uuid();
        $product = $this->makeProduct($this->company, 'RERUN');
        $job = $this->makeCompletedJob($this->company, [
            ['product' => $product, 'barcode' => 'RERUN-1'],
        ]);

        $this->runEnrichment($job, $this->company, new FakeImportCatalogLookup([
            'RERUN1' => TestCatalogLookupResult::found('RERUN1', $platformProductId),
        ]));
        $secondLookup = new FakeImportCatalogLookup([]);
        $this->runEnrichment($job->refresh(), $this->company, $secondLookup);

        $this->assertSame([], $secondLookup->calls);
        $this->assertSame(['enriched' => 1], $job->refresh()->enrichment_summary);
        Queue::assertPushed(ApplyCatalogEnrichmentJob::class, 1);
    }

    public function test_barcode_less_imported_rows_are_counted_in_summary_and_written_to_result_workbook(): void
    {
        $productWithoutColumn = $this->makeProduct($this->company, 'NO-BARCODE-COLUMN');
        $productWithBlankValue = $this->makeProduct($this->company, 'BLANK-BARCODE');
        $job = $this->makeCompletedJob($this->company, [
            ['product' => $productWithoutColumn, 'barcode' => 'temporarily-present'],
            ['product' => $productWithBlankValue, 'barcode' => '   '],
        ]);
        $firstRow = $job->rows()->orderBy('row_number')->firstOrFail();
        $firstRowData = $firstRow->data;
        unset($firstRowData['barcode']);
        $firstRow->update(['data' => $firstRowData]);
        $lookup = new FakeImportCatalogLookup([]);

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertSame([], $lookup->calls);
        $this->assertSame(
            ['enrichment_barcode_missing' => 2],
            $job->refresh()->enrichment_summary,
        );
        $this->assertSame(
            ['enrichment_barcode_missing', 'enrichment_barcode_missing'],
            $job->rows()->orderBy('row_number')->get()->map(
                static fn (ImportRow $row): ?string => $row->warnings[0]['code'] ?? null,
            )->all(),
        );

        $path = $this->app->make(ResultWorkbookService::class)->generate($job->refresh());
        $spreadsheet = IOFactory::load($path);
        unlink($path);
        $sheet = $spreadsheet->getSheetByName('Imported');
        $this->assertNotNull($sheet);
        $this->assertSame('warnings', $sheet->getCell('C1')->getValue());
        $this->assertSame('enrichment_barcode_missing: barcode missing', $sheet->getCell('C2')->getValue());
        $this->assertSame('enrichment_barcode_missing: barcode missing', $sheet->getCell('C3')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_imported_entity_from_sibling_company_is_skipped_before_lookup(): void
    {
        $companyB = $this->makeCompany('Company B');
        $productB = $this->makeProduct($companyB, 'COMPANY-B');
        $job = $this->makeCompletedJob($this->company, [
            ['product' => $productB, 'barcode' => 'COMPANY-B'],
        ]);
        $lookup = new FakeImportCatalogLookup([
            'COMPANYB' => TestCatalogLookupResult::found('COMPANYB', (string) Str::uuid()),
        ]);

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertSame([], $lookup->calls);
        $this->assertNull($productB->refresh()->platform_product_id);
        $this->assertNull($job->rows()->sole()->warnings);
    }

    public function test_same_barcode_in_two_companies_gets_two_backlinks_and_reruns_without_lookups(): void
    {
        Queue::fake();
        $companyB = $this->makeCompany('Company B');
        $platformProductId = (string) Str::uuid();
        $productA = $this->makeProduct($this->company, 'SHARED-A');
        $productB = $this->makeProduct($companyB, 'SHARED-B');
        $jobA = $this->makeCompletedJob($this->company, [['product' => $productA, 'barcode' => 'SHARED-EAN']]);
        $jobB = $this->makeCompletedJob($companyB, [['product' => $productB, 'barcode' => 'SHARED-EAN']]);
        $result = ['SHAREDEAN' => TestCatalogLookupResult::found('SHAREDEAN', $platformProductId)];

        $this->runEnrichment($jobA, $this->company, new FakeImportCatalogLookup($result));
        $this->runEnrichment($jobB, $companyB, new FakeImportCatalogLookup($result));

        $this->assertSame($platformProductId, $productA->refresh()->platform_product_id);
        $this->assertSame($platformProductId, $productB->refresh()->platform_product_id);
        Queue::assertPushed(ApplyCatalogEnrichmentJob::class, 2);

        $rerunA = new FakeImportCatalogLookup([]);
        $rerunB = new FakeImportCatalogLookup([]);
        $this->runEnrichment($jobA->refresh(), $this->company, $rerunA);
        $this->runEnrichment($jobB->refresh(), $companyB, $rerunB);

        $this->assertSame([], $rerunA->calls);
        $this->assertSame([], $rerunB->calls);
        Queue::assertPushed(ApplyCatalogEnrichmentJob::class, 2);
    }

    public function test_at_async_threshold_every_found_row_links_with_company_context_initially_clear(): void
    {
        Queue::fake();
        $platformProductId = (string) Str::uuid();
        $rows = [];
        for ($index = 1; $index <= 100; $index++) {
            $rows[] = [
                'product' => $this->makeProduct($this->company, sprintf('ASYNC-%03d', $index)),
                'barcode' => 'ASYNC-SHARED',
            ];
        }
        $job = $this->makeCompletedJob($this->company, $rows);
        $lookup = new FakeImportCatalogLookup([
            'ASYNCSHARED' => TestCatalogLookupResult::found('ASYNCSHARED', $platformProductId),
        ]);
        $this->app->make(CompanyContext::class)->clear();

        $this->runEnrichment($job, $this->company, $lookup);

        $this->assertSame(['ASYNCSHARED'], $lookup->calls);
        $this->assertSame(100, Product::query()->where('company_id', $this->company->id)->where('platform_product_id', $platformProductId)->count());
        $this->assertSame(['enriched' => 100], $job->refresh()->enrichment_summary);
        $this->assertNull($this->app->make(CompanyContext::class)->getCompanyId());
    }

    /**
     * @param  list<array{product: Product, barcode: string}>  $rows
     */
    private function makeCompletedJob(Company $company, array $rows): ImportJob
    {
        return $this->makeCompletedJobFromIds($company, array_map(
            static fn (array $row): array => ['product_id' => $row['product']->id, 'barcode' => $row['barcode']],
            $rows,
        ));
    }

    /**
     * @param  list<array{product_id: string, barcode: string}>  $rows
     */
    private function makeCompletedJobFromIds(Company $company, array $rows): ImportJob
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Completed,
            'original_filename' => 'products.csv',
            'file_path' => 'imports/products.csv',
            'total_rows' => count($rows),
            'processed_rows' => count($rows),
            'successful_rows' => count($rows),
            'failed_rows' => 0,
            'options' => ['enrichment_enabled' => true],
            'completed_at' => now(),
        ]);

        foreach ($rows as $index => $row) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $index + 1,
                'data' => ['name' => 'Imported product '.$index, 'barcode' => $row['barcode']],
                'is_valid' => true,
                'is_imported' => true,
                'imported_entity_id' => $row['product_id'],
                'outcome' => ImportRowOutcome::Imported,
            ]);
        }

        return $job;
    }

    private function runEnrichment(ImportJob $job, Company $company, FakeImportCatalogLookup $lookup): void
    {
        (new EnrichImportedProductsJob($job->id, $this->tenant->id, $company->id))->handle(
            $this->app->make(CompanyContext::class),
            $lookup,
            $this->app->make(CatalogBacklinkDispatcher::class),
        );
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(10)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeProduct(Company $company, string $sku): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Product '.$sku,
            'sku' => $sku,
            'barcode' => null,
            'platform_product_id' => null,
        ]);
    }
}

final class TestCatalogLookupResult implements CatalogLookupResultInterface
{
    public function __construct(
        private readonly CatalogLookupOutcome $outcome,
        private readonly ?string $platformProductId,
    ) {}

    public static function found(string $barcode, string $platformProductId): self
    {
        return new self(CatalogLookupOutcome::Found, $platformProductId);
    }

    public static function forOutcome(CatalogLookupOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public function outcome(): CatalogLookupOutcome
    {
        return $this->outcome;
    }

    public function platformProductId(): ?string
    {
        return $this->platformProductId;
    }
}

final class FakeImportCatalogLookup implements CatalogLookupInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, TestCatalogLookupResult> $results */
    public function __construct(
        private readonly array $results,
        private readonly ?int $circuitOpensAfterCalls = null,
    ) {}

    public function normalizeBarcode(string $barcode): ?string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]/', '', trim($barcode));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    public function lookup(string $barcode, ?string $vertical = null): CatalogLookupResultInterface
    {
        $normalized = $this->normalizeBarcode($barcode) ?? $barcode;
        $this->calls[] = $normalized;

        return $this->results[$normalized] ?? new TestCatalogLookupResult(CatalogLookupOutcome::NotFound, null);
    }

    public function isCircuitOpen(): bool
    {
        return $this->circuitOpensAfterCalls !== null
            && count($this->calls) >= $this->circuitOpensAfterCalls;
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        return null;
    }
}
