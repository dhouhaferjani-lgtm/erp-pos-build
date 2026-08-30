<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Domain\ImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImportJsonbCastHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_source_without_provided_hydrates_an_empty_mask(): void
    {
        $row = $this->row(['name' => 'Legacy'], null, null);

        $this->assertSame([], $row->data['_provided']);
        $this->assertSame([], $row->data['_results']);
        $this->assertSame('Legacy', $row->data['name']);
    }

    public function test_legacy_source_without_results_hydrates_an_empty_result_map(): void
    {
        $row = $this->row(['name' => 'Mapped', '_provided' => ['name']], null, null);

        $this->assertSame(['name'], $row->data['_provided']);
        $this->assertSame([], $row->data['_results']);
    }

    public function test_null_and_empty_warning_lists_remain_distinct(): void
    {
        $nullWarnings = $this->row(['name' => 'Null'], null, null);
        $emptyWarnings = $this->row(['name' => 'Empty'], [], null);

        $this->assertNull($nullWarnings->warnings);
        $this->assertSame([], $emptyWarnings->warnings);
    }

    public function test_null_and_empty_error_bags_remain_distinct(): void
    {
        $nullErrors = $this->row(['name' => 'Null'], null, null);
        $emptyErrors = $this->row(['name' => 'Empty'], null, []);

        $this->assertNull($nullErrors->errors);
        $this->assertSame([], $emptyErrors->errors);
    }

    public function test_legacy_and_unknown_warning_codes_never_throw_on_read(): void
    {
        $row = $this->row(
            ['name' => 'Warnings'],
            [
                ['code' => 'location_unresolved', 'detail' => 'old location detail', 'ignored' => true],
                ['code' => 'future_warning', 'detail' => 'future detail'],
            ],
            null,
        );

        $warnings = $row->warnings;
        $this->assertNotNull($warnings);
        $this->assertCount(2, $warnings);
        $this->assertSame('location_unresolved', $warnings[0]['code']);
        $this->assertSame('old location detail', $warnings[0]['detail']);
        $this->assertNull($warnings[1]['code']);
        $this->assertSame('future_warning: future detail', $warnings[1]['detail']);
        $this->assertArrayNotHasKey('ignored', $warnings[0]);
    }

    public function test_results_and_placement_plan_round_trip_through_the_typed_source_shape(): void
    {
        $placement = [
            'mode' => 'auto_create',
            'location_id' => 'location-1',
            'location_code' => 'MAIN',
            'path' => 'A1/R2',
            'final_node_id' => null,
            'segments' => [[
                'depth' => 0,
                'existing_node_id' => 'aisle-1',
                'node_type' => 'aisle',
                'name' => 'Aisle 1',
                'code' => 'A1',
                'path' => 'A1',
            ]],
            'nodes_to_create' => [[
                'depth' => 1,
                'existing_node_id' => null,
                'node_type' => 'rack',
                'name' => 'Rack 2',
                'code' => 'R2',
                'path' => 'A1/R2',
            ]],
        ];
        $row = $this->row([
            'name' => 'Placed',
            '_provided' => ['name'],
            '_results' => ['tax_source' => 'company_default'],
            '_placement_plan' => $placement,
        ], null, null);

        $this->assertSame(['tax_source' => 'company_default'], $row->data['_results']);
        $this->assertSame($placement, $row->data['_placement_plan']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, bool|string>>|null  $warnings
     * @param  array<string, list<string>>|null  $errors
     */
    private function row(array $data, ?array $warnings, ?array $errors): ImportRow
    {
        $jobId = (string) Str::uuid();
        DB::table('import_jobs')->insert([
            'id' => $jobId,
            'tenant_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'type' => 'products',
            'status' => 'completed',
            'original_filename' => 'legacy.csv',
            'file_path' => 'imports/legacy.csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rowId = (string) Str::uuid();
        DB::table('import_rows')->insert([
            'id' => $rowId,
            'import_job_id' => $jobId,
            'row_number' => 1,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'errors' => $errors === null ? null : json_encode($errors, JSON_THROW_ON_ERROR),
            'warnings' => $warnings === null ? null : json_encode($warnings, JSON_THROW_ON_ERROR),
            'is_valid' => true,
            'is_imported' => true,
            'outcome' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ImportRow::query()->findOrFail($rowId);
    }
}
