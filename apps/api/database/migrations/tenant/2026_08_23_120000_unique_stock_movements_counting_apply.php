<?php

declare(strict_types=1);

use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H-1 backstop — at most ONE counting-apply movement per
 * (counting, product, location, variant).
 *
 * `ApplyStockAdjustmentsOnCountingCompleted` posts exactly one stock movement
 * per counting line, on both of its paths, each stamped
 * `reference_type = 'inventory_counting'` + `reference_id = <counting id>`
 * (`ApplyStockAdjustmentsOnCountingCompleted.php:240` legacy delta,
 * `:310` replay). Its two idempotency guards — the `replay_audit` marker and
 * the `COUNTING:{number}` movement probe — are both unlocked read-then-write
 * against items loaded before a sibling job's write, so two concurrent applies
 * can each conclude "not applied yet". Every other stock-moving lane already
 * has a DB backstop under its service-level idempotency check
 * (`goods_receipt_lines.movement_id`, `stock_adjustment_lines_movement_id_unique`,
 * `supplier_goods_return_note_lines.movement_id`); counting had none.
 *
 * TWO partial indexes because `variant_id` is nullable and PostgreSQL treats
 * NULLs as distinct — the same shape `inventory_counting_items` uses for the
 * counting line itself (`2025_12_02_070002_...:92-102`), which is what makes
 * "one line per grain per counting" the correct uniqueness for the movement too.
 *
 * MIGRATION-BEARING — pre-flight duplicate scan. Existing rows could violate
 * this, so the scan below runs FIRST and aborts the migration for that tenant
 * rather than letting `CREATE UNIQUE INDEX` fail with an opaque 23505. Run this
 * census on every tenant database BEFORE deploying (per-tenant; the platform is
 * database-per-tenant):
 *
 *   SELECT reference_id AS counting_id,
 *          product_id,
 *          location_id,
 *          variant_id,
 *          COUNT(*) AS duplicate_count
 *   FROM stock_movements
 *   WHERE reference_type = 'inventory_counting'
 *     AND reference_id IS NOT NULL
 *   GROUP BY reference_id, product_id, location_id, variant_id
 *   HAVING COUNT(*) > 1
 *   ORDER BY duplicate_count DESC;
 *
 * A non-empty result means that tenant already suffered the double-apply this
 * index prevents. Do NOT delete a movement to make the index build: a posted
 * movement is stock history. Reconcile it with a contra stock adjustment
 * document first, then re-run — the duplicate rows stay, so such a tenant needs
 * the census rows resolved by hand (re-linking the surplus movement to its own
 * correcting document) before this migration can complete.
 */
return new class extends Migration
{
    private const INDEX_NULL_VARIANT = 'stock_movements_counting_apply_unique_nv';

    private const INDEX_WITH_VARIANT = 'stock_movements_counting_apply_unique_v';

    public function up(): void
    {
        // pgsql-guarded: SQLite has no partial indexes, and every tenant
        // database is PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $referenceType = StockMovementReferenceType::InventoryCounting->value;
        // The predicate cannot be parameterised (CREATE INDEX takes no
        // bindings), so quote it the way the journal-entries precedent does.
        $quotedType = str_replace("'", "''", $referenceType);

        $duplicates = DB::select(
            'SELECT reference_id, product_id, location_id, variant_id, COUNT(*) AS duplicate_count
             FROM stock_movements
             WHERE reference_type = ?
               AND reference_id IS NOT NULL
             GROUP BY reference_id, product_id, location_id, variant_id
             HAVING COUNT(*) > 1',
            [$referenceType]
        );

        if ($duplicates !== []) {
            $first = $duplicates[0];
            throw new RuntimeException(sprintf(
                'Cannot create the counting-apply unique indexes: %d duplicate grain(s) already exist. '
                .'First: counting %s, product %s, location %s, variant %s (%s rows). '
                .'Resolve with a contra stock adjustment before migrating.',
                count($duplicates),
                (string) $first->reference_id,
                (string) $first->product_id,
                (string) $first->location_id,
                $first->variant_id === null ? 'NULL' : (string) $first->variant_id,
                (string) $first->duplicate_count,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_NULL_VARIANT
            ." ON stock_movements (reference_id, product_id, location_id)
               WHERE reference_type = '{$quotedType}'
                 AND reference_id IS NOT NULL
                 AND variant_id IS NULL"
        );

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_WITH_VARIANT
            ." ON stock_movements (reference_id, product_id, location_id, variant_id)
               WHERE reference_type = '{$quotedType}'
                 AND reference_id IS NOT NULL
                 AND variant_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NULL_VARIANT);
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_WITH_VARIANT);
    }
};
