<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign W4-6 gate r2 (NEW-1) — the same-second tie-break.
 *
 * `final_qty_as_of` and `stock_movements.occurred_at`/`created_at` are BOTH
 * stored at second precision, so a sale rung up in the same second as the count
 * cannot be ordered against it by time. r1 closed the replay window at both ends
 * (`[from, to]`), which fixed the phantom GAIN when the counter had not seen the
 * sale — and created the mirror defect when they had: the same units subtracted
 * twice (gate probe P2b, on-hand 56 → 52).
 *
 * A timestamp cannot decide it. INSERTION ORDER can. These columns record the
 * last `stock_movements.id` that existed on the counted stock line at the moment
 * the count was SUBMITTED; the replay then treats a same-second movement as
 * baseline when its id is at or below the marker (it was already there when the
 * counter reported) and neutralises it when it is above (it arrived after).
 * `stock_movements.id` is a UUIDv7, so lexicographic order is creation order.
 *
 * NULLABLE and additive by design: a legacy row, or a line counted before this
 * migration, carries no marker and the replay falls back to the pre-existing
 * `[from, to]` timestamp semantics unchanged. Self-guarding (`hasColumn`) so a
 * re-run on a partially migrated tenant is a no-op — every tenant database is
 * migrated independently on deploy.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'count_1_movement_marker',
        'count_2_movement_marker',
        'count_3_movement_marker',
        'final_qty_movement_marker',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('inventory_counting_items')) {
            return;
        }

        Schema::table('inventory_counting_items', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('inventory_counting_items', $column)) {
                    continue;
                }

                // Deliberately NOT a foreign key: the marker is an ordering
                // token, and it must survive the movement row it names being
                // archived or deleted.
                $table->uuid($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_counting_items')) {
            return;
        }

        Schema::table('inventory_counting_items', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('inventory_counting_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
