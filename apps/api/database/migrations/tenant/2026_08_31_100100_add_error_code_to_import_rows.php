<?php

declare(strict_types=1);

use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            Log::info('import_rows.error_code.skipped', ['driver' => $driver]);
            MigrationOutput::info('import_rows.error_code.skipped');

            return;
        }

        if (! Schema::hasTable('import_rows')) {
            Log::info('import_rows.error_code.skipped', ['reason' => 'table_missing']);
            MigrationOutput::info('import_rows.error_code.skipped');

            return;
        }

        if (! Schema::hasColumn('import_rows', 'import_error_code')) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->string('import_error_code', 64)->nullable();
            });
        }

        if (! Schema::hasIndex('import_rows', ['import_error_code'])) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->index('import_error_code', 'import_rows_import_error_code_index');
            });
        }

        if (! Schema::hasColumn('import_rows', 'import_error_detail')) {
            Schema::table('import_rows', function (Blueprint $table): void {
                $table->jsonb('import_error_detail')->nullable();
            });
        }
    }

    /**
     * Forward-only because coded row failures become durable operator history;
     * dropping the columns would erase their machine-readable meaning.
     */
    public function down(): void
    {
        Log::info('import_rows.error_code: forward-only migration, down() is a no-op.');
    }
};
