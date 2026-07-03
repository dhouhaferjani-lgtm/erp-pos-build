<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS obb_import_ref_type_unique ON opening_balance_batches (((import_file_reference->>'import_job_id')), type) WHERE import_file_reference IS NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS obb_import_ref_type_unique');
    }
};
