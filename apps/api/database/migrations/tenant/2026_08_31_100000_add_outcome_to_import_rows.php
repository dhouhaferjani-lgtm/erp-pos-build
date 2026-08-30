<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array OUTCOME_INDEX_COLUMNS = ['import_job_id', 'outcome'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            Log::info('import_rows.outcome_backfill.skipped', ['driver' => $driver]);

            return;
        }

        // G-4 and G-6a may land in either order. Each lane owns a guarded
        // addition so neither migration order attempts duplicate DDL.
        if (Schema::hasTable('import_jobs') && ! Schema::hasColumn('import_jobs', 'skipped_rows')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->unsignedInteger('skipped_rows')->default(0)->after('successful_rows');
            });
        }

        if (! Schema::hasTable('import_rows')) {
            Log::info('import_rows.outcome_backfill.skipped', ['reason' => 'table_missing']);

            return;
        }

        if (! Schema::hasColumn('import_rows', 'outcome')) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->string('outcome', 32)->default('pending');
            });
        }

        if (! Schema::hasColumn('import_rows', 'duplicate_bucket')) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->string('duplicate_bucket', 24)->nullable();
            });
        }

        if (! Schema::hasIndex('import_rows', self::OUTCOME_INDEX_COLUMNS)) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->index(self::OUTCOME_INDEX_COLUMNS, 'import_rows_import_job_id_outcome_index');
            });
        }

        $pending = DB::table('import_rows')->where('outcome', 'pending');
        $imported = (clone $pending)->where('is_imported', true)->count();
        $failedValidation = (clone $pending)
            ->where('is_imported', false)
            ->where('is_valid', false)
            ->count();
        $failedExecution = (clone $pending)
            ->where('is_imported', false)
            ->where('is_valid', true)
            ->whereNotNull('import_error')
            ->count();
        $stillPending = (clone $pending)
            ->where('is_imported', false)
            ->where('is_valid', true)
            ->whereNull('import_error')
            ->count();

        (clone $pending)->where('is_imported', true)->update(['outcome' => 'imported']);
        (clone $pending)
            ->where('is_imported', false)
            ->where('is_valid', false)
            ->update(['outcome' => 'failed']);
        (clone $pending)
            ->where('is_imported', false)
            ->where('is_valid', true)
            ->whereNotNull('import_error')
            ->update(['outcome' => 'failed']);

        // Historical data predates duplicate policy decisions, so neither
        // duplicate_skipped nor duplicate_loser can appear in this backfill.
        $census = [
            'imported' => $imported,
            'failed_validation' => $failedValidation,
            'failed_execution' => $failedExecution,
            'pending' => $stillPending,
        ];
        Log::info('import_rows.outcome_backfill', $census);
        $line = sprintf(
            "import_rows.outcome_backfill imported=%d failed_validation=%d failed_execution=%d pending=%d\n",
            $imported,
            $failedValidation,
            $failedExecution,
            $stillPending,
        );
        if (App::runningInConsole()) {
            fwrite(STDOUT, $line);
        }
    }

    /**
     * Forward-only because terminal import-row decisions may be written after
     * deployment and cannot be represented faithfully by the legacy booleans.
     */
    public function down(): void
    {
        Log::info('import_rows.outcome_backfill: forward-only migration, down() is a no-op.');
    }
};
