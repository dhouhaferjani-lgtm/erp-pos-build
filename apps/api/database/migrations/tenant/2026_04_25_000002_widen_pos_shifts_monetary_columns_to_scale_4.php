<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Single multi-clause ALTER TABLE -> one ACCESS EXCLUSIVE lock + one table rewrite.
        DB::statement('
            ALTER TABLE pos_shifts
                ALTER COLUMN opening_cash  TYPE DECIMAL(16, 4),
                ALTER COLUMN expected_cash TYPE DECIMAL(16, 4),
                ALTER COLUMN actual_cash   TYPE DECIMAL(16, 4),
                ALTER COLUMN variance      TYPE DECIMAL(16, 4)
        ');

        // Existing CHECK pos_shifts_variance_calc stays — arithmetic is exact at scale 4.
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        // Narrowing is lossy in general; down-migration intentionally no-op to avoid truncation.
        // If a rollback is needed, manually widen in code and re-deploy.
    }
};
