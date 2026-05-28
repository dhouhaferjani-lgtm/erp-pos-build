<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\Exceptions\LargeMigrationRefusalException;
use App\Modules\Inventory\Domain\Events\StockLevelsMigratedToDefaultVariant;
use Illuminate\Support\Facades\DB;

/**
 * Atomically migrates a product's pre-existing product-level state
 * (variant_id IS NULL) to the newly-created default variant.
 *
 * Tables migrated (within one DB transaction):
 *   - stock_levels         — all rows for product with variant_id NULL
 *   - stock_reservations   — rows with variant_id NULL AND released_at IS NULL (open only)
 *   - product_batches      — rows with variant_id NULL AND is_active = true
 *   - recipe_lines         — rows where component_type = 'product', component_id = $productId,
 *                            component_variant_id IS NULL
 *
 * NOT touched:
 *   - stock_movements — append-only audit log; historical rows must never be rewritten.
 *
 * Large-migration guard:
 *   Before opening the transaction an estimate of affected rows across the four tables
 *   is computed. If the total exceeds LARGE_MIGRATION_THRESHOLD and $allowLargeMigration
 *   is false a LargeMigrationRefusalException is thrown. The caller may pass
 *   $allowLargeMigration = true to bypass the guard (e.g. planned bulk migrations).
 */
final class StockLevelMigrationService
{
    private const LARGE_MIGRATION_THRESHOLD = 5000;

    /**
     * Migrate all product-level state for $productId to $defaultVariantId.
     *
     * @throws LargeMigrationRefusalException when the estimated row count exceeds the
     *                                        threshold and $allowLargeMigration is false.
     */
    public function migrateToDefaultVariant(
        string $productId,
        string $defaultVariantId,
        bool $allowLargeMigration = false,
    ): void {
        $estimate = $this->estimateAffectedRows($productId);

        if ($estimate > self::LARGE_MIGRATION_THRESHOLD && ! $allowLargeMigration) {
            throw new LargeMigrationRefusalException(
                "Migration would affect {$estimate} rows; exceeds threshold "
                .self::LARGE_MIGRATION_THRESHOLD
                .'. Pass allowLargeMigration=true to override.'
            );
        }

        DB::transaction(function () use ($productId, $defaultVariantId): void {
            // 1. stock_levels — all product-level rows (regardless of quantity/status).
            DB::table('stock_levels')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            // 2. stock_reservations — open reservations only (released_at IS NULL).
            //    Table is scoped by company_id (not tenant_id) but the WHERE on
            //    product_id + variant_id NULL + released_at NULL is sufficient.
            DB::table('stock_reservations')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->whereNull('released_at')
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            // 3. product_batches — active batches only (is_active = true).
            DB::table('product_batches')
                ->where('product_id', $productId)
                ->whereNull('variant_id')
                ->where('is_active', true)
                ->update(['variant_id' => $defaultVariantId, 'updated_at' => now()]);

            // 4. recipe_lines — lines whose component IS this product and have no
            //    variant pinned yet.
            DB::table('recipe_lines')
                ->where('component_type', 'product')
                ->where('component_id', $productId)
                ->whereNull('component_variant_id')
                ->update(['component_variant_id' => $defaultVariantId, 'updated_at' => now()]);

            // Dispatch event only after the outermost transaction commits so that
            // reactors never observe an event whose writes were subsequently rolled back.
            DB::afterCommit(function () use ($productId, $defaultVariantId): void {
                event(new StockLevelsMigratedToDefaultVariant(
                    productId: $productId,
                    defaultVariantId: $defaultVariantId,
                ));
            });
        });
    }

    /**
     * Estimate the number of rows that would be affected by the migration.
     *
     * Uses the same WHERE predicates as the actual UPDATE statements so that
     * the guard is an accurate (not over-conservative) gate.
     */
    private function estimateAffectedRows(string $productId): int
    {
        $stockLevels = DB::table('stock_levels')
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->count();

        $reservations = DB::table('stock_reservations')
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->whereNull('released_at')
            ->count();

        $batches = DB::table('product_batches')
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->where('is_active', true)
            ->count();

        $recipeLines = DB::table('recipe_lines')
            ->where('component_type', 'product')
            ->where('component_id', $productId)
            ->whereNull('component_variant_id')
            ->count();

        return $stockLevels + $reservations + $batches + $recipeLines;
    }
}
