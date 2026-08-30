<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\DuplicateCensusService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DuplicateCensusTest extends TestCase
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
        Location::factory()->for($this->company)->create(['code' => 'MAIN']);
        Location::factory()->for($this->company)->create(['code' => 'ANNEX']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_census_classifies_all_six_hundred_rows_and_persists_every_bucket(): void
    {
        $this->product('EXISTING-SKU', 'Existing SKU', null);
        $this->product('BARCODE-HOLDER', 'Existing barcode', 'LIVE-BARCODE');
        $this->product('NAME-HOLDER', 'Existing by Name', null);

        $rows = [
            1 => ['name' => 'SKU row', 'sku' => 'EXISTING-SKU', 'location_code' => 'MAIN'],
            2 => ['name' => 'Barcode row', 'barcode' => 'LIVE-BARCODE', 'location_code' => 'MAIN'],
            3 => ['name' => ' existing by name ', 'location_code' => 'MAIN'],
        ];
        for ($row = 4; $row <= 600; $row++) {
            $rows[$row] = ['name' => 'New '.$row, 'sku' => 'NEW-'.$row, 'location_code' => 'MAIN'];
        }

        $job = $this->job(600);
        app(ImportService::class)->addRowsBatch($job, $rows);
        DB::table('import_rows')->where('import_job_id', $job->id)->update(['is_valid' => true]);

        $census = app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);

        $this->assertSame(597, $census->counts[DuplicateBucket::New->value]);
        $this->assertSame(1, $census->counts[DuplicateBucket::ExistingSku->value]);
        $this->assertSame(1, $census->counts[DuplicateBucket::ExistingBarcode->value]);
        $this->assertSame(1, $census->counts[DuplicateBucket::ExistingName->value]);
        $this->assertSame([3], $census->matchedByName);
        $this->assertSame(600, $job->rows()->whereNotNull('duplicate_bucket')->count());
        $options = $job->refresh()->options;
        $this->assertIsArray($options);
        $this->assertSame($census->toStorage(), $options['duplicate_census']);
    }

    public function test_only_a_repeated_product_at_the_same_location_has_an_in_file_loser(): void
    {
        $job = $this->job(3);
        app(ImportService::class)->addRowsBatch($job, [
            1 => ['name' => 'One', 'sku' => 'SAME', 'location_code' => 'MAIN'],
            2 => ['name' => 'Two', 'sku' => 'SAME', 'location_code' => 'ANNEX'],
            3 => ['name' => 'Three', 'sku' => 'SAME', 'location_code' => 'MAIN'],
        ]);
        DB::table('import_rows')->where('import_job_id', $job->id)->update(['is_valid' => true]);

        $census = app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);
        $rows = $job->rows()->orderBy('row_number')->get();
        $first = $rows->get(0);
        $second = $rows->get(1);
        $third = $rows->get(2);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotNull($third);

        $this->assertSame(DuplicateBucket::InFile, $first->duplicate_bucket);
        $this->assertSame(DuplicateBucket::New, $second->duplicate_bucket);
        $this->assertSame(DuplicateBucket::New, $third->duplicate_bucket);
        $this->assertSame(1, $census->counts[DuplicateBucket::InFile->value]);
    }

    public function test_census_excludes_invalid_rows_and_batches_each_identity_arm_per_chunk(): void
    {
        $job = $this->job(601);
        $rows = [];
        for ($number = 1; $number <= 600; $number++) {
            $rows[$number] = [
                'name' => 'Product '.$number,
                'sku' => 'SKU-'.$number,
                'barcode' => 'BAR-'.$number,
                'location_code' => 'MAIN',
            ];
        }
        $rows[601] = ['name' => '', 'sku' => 'INVALID', 'location_code' => 'MAIN'];
        app(ImportService::class)->addRowsBatch($job, $rows);
        DB::table('import_rows')->where('import_job_id', $job->id)->where('row_number', '<=', 600)->update([
            'is_valid' => true,
        ]);
        DB::table('import_rows')->where('import_job_id', $job->id)->where('row_number', 601)->update([
            'is_valid' => false,
            'outcome' => ImportRowOutcome::Failed->value,
        ]);

        $productLookups = 0;
        DB::listen(static function ($query) use (&$productLookups): void {
            if (str_contains(strtolower($query->sql), 'from "products"')) {
                $productLookups++;
            }
        });

        $census = app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);

        $this->assertSame(600, array_sum($census->counts));
        $this->assertNull($job->rows()->where('row_number', 601)->firstOrFail()->duplicate_bucket);
        $this->assertGreaterThan(0, $productLookups);
        $this->assertLessThanOrEqual(6, $productLookups, 'Three identity-arm lookups per 500-row chunk is the ceiling.');
    }

    public function test_location_codes_coalesce_by_resolved_id_and_unknown_locations_remain_distinct(): void
    {
        $job = $this->job(4);
        app(ImportService::class)->addRowsBatch($job, [
            1 => ['name' => 'One', 'sku' => 'SAME', 'location_code' => ' MAIN '],
            2 => ['name' => 'Two', 'sku' => 'SAME', 'location_code' => 'MAIN'],
            3 => ['name' => 'Three', 'sku' => 'UNKNOWN', 'location_code' => 'NOPE'],
            4 => ['name' => 'Four', 'sku' => 'UNKNOWN', 'location_code' => 'NOPE'],
        ]);
        DB::table('import_rows')->where('import_job_id', $job->id)->update(['is_valid' => true]);

        app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);
        $rows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $first = $rows->get(1);
        $second = $rows->get(2);
        $third = $rows->get(3);
        $fourth = $rows->get(4);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotNull($third);
        $this->assertNotNull($fourth);

        $this->assertSame(DuplicateBucket::InFile, $first->duplicate_bucket);
        $this->assertSame(DuplicateBucket::New, $second->duplicate_bucket);
        $this->assertSame(DuplicateBucket::New, $third->duplicate_bucket);
        $this->assertSame(DuplicateBucket::New, $fourth->duplicate_bucket);
        foreach ([$third, $fourth] as $unresolved) {
            $this->assertContains(
                ImportWarningCode::LocationUnresolved->value,
                array_column($unresolved->warnings ?? [], 'code'),
            );
        }
    }

    public function test_execution_re_resolves_advisory_preview_and_applies_policy_to_fresh_identity(): void
    {
        $job = $this->job(1);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);
        app(ImportService::class)->addRowsBatch($job, [
            1 => ['name' => 'Race product', 'sku' => 'RACE', 'location_code' => 'MAIN'],
        ]);
        DB::table('import_rows')->where('import_job_id', $job->id)->update(['is_valid' => true]);
        app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);
        $row = $job->rows()->firstOrFail();
        $this->assertSame(DuplicateBucket::New, $row->duplicate_bucket);

        $this->product('RACE', 'Created after preview', null);
        $outcome = app(ImportService::class)->processPendingRow($job->refresh(), $row->refresh());

        $this->assertSame(ImportRowOutcome::DuplicateSkipped, $outcome);
        $this->assertSame(1, Product::query()->where('company_id', $this->company->id)->where('sku', 'RACE')->count());
        $this->assertContains(
            ImportWarningCode::PreviewDrift->value,
            array_column($row->refresh()->warnings ?? [], 'code'),
        );
    }

    public function test_census_and_execution_share_the_product_resolution_ladder_for_every_outcome(): void
    {
        $this->product('SKU-HOLDER', 'SKU holder', null);
        $this->product('BARCODE-HOLDER', 'Barcode holder', 'ONE-BARCODE');
        $this->product('NAME-HOLDER', 'Name holder', null);
        $deletedSku = $this->product('DELETED-SKU', 'Deleted supplied SKU', null);
        $deletedSku->delete();
        $deletedName = $this->product('DELETED-NAME-HOLDER', 'Deleted name holder', null);
        $deletedName->delete();
        $this->product('AMBIGUOUS-A', 'Ambiguous A', 'AMBIGUOUS-BARCODE');
        $this->product('AMBIGUOUS-B', 'Ambiguous B', 'AMBIGUOUS-BARCODE');

        $job = $this->job(6);
        $job->update(['options' => ['duplicate_policy' => 'skip']]);
        app(ImportService::class)->addRowsBatch($job, [
            1 => ['name' => 'SKU input', 'sku' => 'SKU-HOLDER', 'location_code' => 'MAIN'],
            2 => ['name' => 'Barcode input', 'barcode' => 'ONE-BARCODE', 'location_code' => 'MAIN'],
            3 => ['name' => ' name holder ', 'location_code' => 'MAIN'],
            4 => ['name' => 'Replacement', 'sku' => 'DELETED-SKU', 'location_code' => 'MAIN'],
            5 => ['name' => 'Deleted name holder', 'location_code' => 'MAIN'],
            6 => ['name' => 'Ambiguous input', 'barcode' => 'AMBIGUOUS-BARCODE', 'location_code' => 'MAIN'],
        ]);
        DB::table('import_rows')->where('import_job_id', $job->id)->update(['is_valid' => true]);

        app(DuplicateCensusService::class)->census($job->refresh(), $this->company->id);
        $previewRows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');
        $expectedBuckets = [
            1 => DuplicateBucket::ExistingSku,
            2 => DuplicateBucket::ExistingBarcode,
            3 => DuplicateBucket::ExistingName,
            4 => DuplicateBucket::New,
            5 => DuplicateBucket::New,
            6 => DuplicateBucket::New,
        ];
        foreach ($expectedBuckets as $rowNumber => $bucket) {
            $this->assertSame($bucket, $previewRows->get($rowNumber)?->duplicate_bucket);
        }

        $expectedOutcomes = [
            1 => ImportRowOutcome::DuplicateSkipped,
            2 => ImportRowOutcome::DuplicateSkipped,
            3 => ImportRowOutcome::DuplicateSkipped,
            4 => ImportRowOutcome::Failed,
            5 => ImportRowOutcome::Failed,
            6 => ImportRowOutcome::Failed,
        ];
        foreach ($expectedOutcomes as $rowNumber => $outcome) {
            $row = $job->rows()->where('row_number', $rowNumber)->firstOrFail();
            $this->assertSame($outcome, app(ImportService::class)->processPendingRow($job->refresh(), $row));
            $this->assertSame($expectedBuckets[$rowNumber], $row->refresh()->duplicate_bucket);
        }

        $this->assertSame(
            ImportErrorCode::SkuHeldByDeletedProduct,
            $job->rows()->where('row_number', 4)->firstOrFail()->import_error_code,
        );
        $this->assertSame(
            ImportErrorCode::SkuHeldByDeletedProduct,
            $job->rows()->where('row_number', 5)->firstOrFail()->import_error_code,
        );
        $this->assertSame(
            ImportErrorCode::BarcodeAmbiguous,
            $job->rows()->where('row_number', 6)->firstOrFail()->import_error_code,
        );
    }

    private function job(int $totalRows): ImportJob
    {
        return app(ImportService::class)->createJob(
            $this->tenant->id,
            $this->user->id,
            ImportType::Products,
            'products.csv',
            'imports/products.csv',
            $totalRows,
        );
    }

    private function product(string $sku, string $name, ?string $barcode): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'barcode' => $barcode,
        ]);
    }
}
