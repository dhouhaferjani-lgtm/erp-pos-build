<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX = 'products_company_barcode_live_unique';

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        $groups = DB::table('products')
            ->select(['company_id', 'barcode'])
            ->selectRaw('COUNT(*) AS row_count')
            ->whereNotNull('barcode')
            ->whereNull('deleted_at')
            ->groupBy('company_id', 'barcode')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('barcode')
            ->get();

        foreach ($groups as $group) {
            /** @var object{company_id: string, barcode: string, row_count: int|string} $group */
            $products = DB::table('products')
                ->where('company_id', $group->company_id)
                ->where('barcode', $group->barcode)
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id']);
            $keptId = $products->first()?->id;
            $clearedIds = $products->skip(1)->pluck('id')->all();
            if (! is_string($keptId) || $clearedIds === []) {
                continue;
            }

            DB::table('products')->whereIn('id', $clearedIds)->update([
                'barcode' => null,
                'updated_at' => now(),
            ]);
            Log::warning('Cleared pre-existing product barcode twins before enforcing uniqueness.', [
                'company_id' => $group->company_id,
                'barcode' => $group->barcode,
                'kept_id' => $keptId,
                'cleared_ids' => $clearedIds,
            ]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON products (company_id, barcode)'
            .' WHERE barcode IS NOT NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
