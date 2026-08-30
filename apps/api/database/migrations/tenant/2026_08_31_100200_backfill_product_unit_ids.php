<?php

declare(strict_types=1);

use App\Modules\Product\Application\Services\ProductUnitBackfillService;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $requirements = [
            'products' => ['id', 'company_id', 'unit', 'unit_id'],
            'units' => ['id', 'tenant_id', 'category_id', 'code', 'name', 'symbol', 'decimal_places', 'is_active'],
            'unit_categories' => ['id', 'name'],
            'companies' => ['id', 'tenant_id'],
        ];
        $missing = [];
        foreach ($requirements as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }
        if ($missing !== []) {
            $context = ['missing' => $missing];
            Log::info('products.unit_id_backfill.skipped', $context);
            MigrationOutput::info('products.unit_id_backfill skipped missing='.implode(',', $missing));

            return;
        }

        try {
            $result = App::make(ProductUnitBackfillService::class)->backfill();
        } catch (Throwable $exception) {
            Log::warning('products.unit_id_backfill.skipped', ['reason' => $exception->getMessage()]);
            MigrationOutput::error('products.unit_id_backfill skipped unexpected_legacy_state');

            return;
        }
        $context = [
            'mapped' => $result->mapped,
            'ambiguous' => $result->ambiguous,
            'unknown' => $result->unknown,
            'missing_company' => $result->missingCompany,
        ];
        Log::info('products.unit_id_backfill', $context);
        $line = sprintf(
            'products.unit_id_backfill mapped=%d ambiguous=%d unknown=%d missing_company=%d',
            $result->mapped,
            $result->ambiguous,
            $result->unknown,
            $result->missingCompany,
        );
        MigrationOutput::info($line);
    }

    /**
     * Forward-only: reversing an evidence-based unit link would destroy a valid
     * relationship and cannot reconstruct the previous ambiguity safely.
     */
    public function down(): void
    {
        Log::info('products.unit_id_backfill.down_skipped', ['reason' => 'forward-only data repair']);
    }
};
