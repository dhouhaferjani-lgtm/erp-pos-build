<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImportRowOutcomeBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_FILE = '2026_08_31_100000_add_outcome_to_import_rows.php';

    private \Closure $runMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $path = database_path('migrations/tenant/'.self::MIGRATION_FILE);
        $this->assertFileExists($path);
        $migration = require $path;
        $this->runMigration = $migration->up(...);
    }

    public function test_backfill_maps_all_four_historical_states_with_imported_precedence_and_logs_census(): void
    {
        $this->dropOutcomeShape();
        $jobId = $this->insertJob();
        $imported = $this->insertRow($jobId, 1, true, true, 'stale error');
        $failedValidation = $this->insertRow($jobId, 2, false, false, null);
        $failedExecution = $this->insertRow($jobId, 3, true, false, 'writer failed');
        $pending = $this->insertRow($jobId, 4, true, false, null);
        $log = Log::spy();

        ob_start();
        ($this->runMigration)();
        $output = (string) ob_get_clean();

        $this->assertSame('imported', DB::table('import_rows')->where('id', $imported)->value('outcome'));
        $this->assertSame('failed', DB::table('import_rows')->where('id', $failedValidation)->value('outcome'));
        $this->assertSame('failed', DB::table('import_rows')->where('id', $failedExecution)->value('outcome'));
        $this->assertSame('pending', DB::table('import_rows')->where('id', $pending)->value('outcome'));
        $this->assertSame('', $output, 'Console diagnostics must not use echo/output buffering.');
        $log->shouldHaveReceived('info', [
            'import_rows.outcome_backfill',
            ['imported' => 1, 'failed_validation' => 1, 'failed_execution' => 1, 'pending' => 1],
        ]);
    }

    public function test_partial_repair_with_columns_present_completes_the_index_and_backfill(): void
    {
        $this->dropOutcomeShape();
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->string('outcome', 32)->default('pending');
            $table->string('duplicate_bucket', 24)->nullable();
        });
        $jobId = $this->insertJob();
        $rowId = $this->insertRow($jobId, 1, true, true, null);

        ob_start();
        ($this->runMigration)();
        ob_end_clean();

        $this->assertTrue(Schema::hasIndex('import_rows', ['import_job_id', 'outcome']));
        $this->assertSame('imported', DB::table('import_rows')->where('id', $rowId)->value('outcome'));
    }

    public function test_skipped_rows_addition_is_guarded_for_either_lane_order(): void
    {
        $this->assertTrue(Schema::hasColumn('import_jobs', 'skipped_rows'));

        ob_start();
        ($this->runMigration)();
        ob_end_clean();

        $this->assertTrue(Schema::hasColumn('import_jobs', 'skipped_rows'));
        $this->assertSame(0, DB::table('import_jobs')->where('id', $this->insertJob())->value('skipped_rows'));
    }

    private function dropOutcomeShape(): void
    {
        if (Schema::hasIndex('import_rows', ['import_job_id', 'outcome'])) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->dropIndex(['import_job_id', 'outcome']);
            });
        }

        Schema::table('import_rows', function (Blueprint $table): void {
            if (Schema::hasColumn('import_rows', 'duplicate_bucket')) {
                $table->dropColumn('duplicate_bucket');
            }
            if (Schema::hasColumn('import_rows', 'outcome')) {
                $table->dropColumn('outcome');
            }
        });
    }

    private function insertJob(): string
    {
        $id = (string) Str::uuid();
        DB::table('import_jobs')->insert([
            'id' => $id,
            'tenant_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'type' => 'products',
            'status' => 'validated',
            'original_filename' => 'historical.csv',
            'file_path' => 'imports/historical.csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertRow(string $jobId, int $number, bool $isValid, bool $isImported, ?string $error): string
    {
        $id = (string) Str::uuid();
        DB::table('import_rows')->insert([
            'id' => $id,
            'import_job_id' => $jobId,
            'row_number' => $number,
            'data' => json_encode(['name' => 'Row '.$number], JSON_THROW_ON_ERROR),
            'is_valid' => $isValid,
            'is_imported' => $isImported,
            'import_error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
