<?php

declare(strict_types=1);

use App\Shared\Precision\PercentScaleDriftScanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $findings = (new PercentScaleDriftScanner)->scanCurrentConnection();
        if ($findings !== []) {
            $formatted = collect($findings)
                ->map(fn (array $finding): string => "{$finding['table']}.{$finding['column']}={$finding['count']}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot narrow percent columns to scale 2; run precision:scan-percent-scale-drift and resolve rows first: '.$formatted
            );
        }

        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE workshop_service_bundles ALTER COLUMN tax_rate TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE workshop_work_order_lines ALTER COLUMN tax_rate TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE products ALTER COLUMN target_margin_override TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE products ALTER COLUMN minimum_margin_override TYPE NUMERIC(5, 2)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Rollback widens the schema only. It cannot recover decimals that a
        // prior unsafe narrowing would have discarded; the up() preflight and
        // fleet scan are the data-safety gates.
        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(6, 3)');
        DB::statement('ALTER TABLE workshop_service_bundles ALTER COLUMN tax_rate TYPE NUMERIC(6, 3)');
        DB::statement('ALTER TABLE workshop_work_order_lines ALTER COLUMN tax_rate TYPE NUMERIC(6, 3)');
        DB::statement('ALTER TABLE products ALTER COLUMN target_margin_override TYPE NUMERIC(5, 3)');
        DB::statement('ALTER TABLE products ALTER COLUMN minimum_margin_override TYPE NUMERIC(5, 3)');
    }
};
