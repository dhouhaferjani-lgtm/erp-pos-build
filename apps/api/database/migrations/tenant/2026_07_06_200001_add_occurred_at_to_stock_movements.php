<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `occurred_at` (event time) to the append-only `stock_movements` audit log.
 *
 * `occurred_at` is the time the stock effect physically happened, which — for
 * device-authored POS sales projected later — is the DEVICE event time, not the
 * server row-insert time (`created_at`). The live-inventory-counting feature
 * replays movements by `occurred_at` to reconcile a physical count taken while
 * sales continue, so every writer must stamp it (device time for POS paths,
 * `now()` otherwise).
 *
 * Follows the concurrent-index pattern of
 * `2026_06_02_100006_add_variant_id_to_stock_movements.php`:
 *  - `$withinTransaction = false` so `CREATE INDEX CONCURRENTLY` (which cannot
 *    run inside a transaction) is legal.
 *  - the column add is a plain nullable `ALTER TABLE ADD COLUMN` (instant on PG).
 *  - existing rows are backfilled to `created_at` in bounded batches so a large
 *    table is not rewritten in one long-held write.
 *
 * Quantities are already `decimal(15,4)` since
 * `2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php`; this
 * migration makes NO scale changes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Backfill batch size — bounds each UPDATE so the append-only log is
     * migrated without one long-held row-lock sweep on a large table.
     */
    private const BACKFILL_BATCH = 10000;

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Nullable ADD COLUMN with no default → instant metadata-only change
            // on PostgreSQL. `after()` is a no-op outside MySQL.
            $table->timestampTz('occurred_at')->nullable()->after('is_historical');
        });

        // Backfill legacy rows: occurred_at := created_at. Batched + idempotent
        // (re-runnable) — the loop terminates once no NULL rows remain.
        $this->backfillOccurredAt();

        // The replay read path scans (product, location, variant, occurred_at);
        // CONCURRENTLY keeps the append-only log writable during the build.
        // PG-only: SQLite/other drivers have no CONCURRENTLY and are only used
        // in tests, where the plain index below is unnecessary for correctness.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_movements_replay
                ON stock_movements (product_id, location_id, variant_id, occurred_at)');
        }
    }

    /**
     * Copy `created_at` into `occurred_at` for every row that still has a NULL
     * `occurred_at`, in bounded batches until none remain. Writes ONLY the new
     * column — the audit log's historical values are never rewritten.
     *
     * Exposed so the migration's backfill contract can be exercised directly by
     * a test (the enclosing `up()` cannot be re-run once the column exists).
     */
    public function backfillOccurredAt(): void
    {
        do {
            $updated = DB::update(
                'UPDATE stock_movements SET occurred_at = created_at
                 WHERE id IN (
                     SELECT id FROM stock_movements WHERE occurred_at IS NULL LIMIT '.self::BACKFILL_BATCH.'
                 )'
            );
        } while ($updated > 0);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_stock_movements_replay');
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('occurred_at');
        });
    }
};
