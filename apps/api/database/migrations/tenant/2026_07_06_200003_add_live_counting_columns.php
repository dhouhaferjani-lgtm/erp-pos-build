<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live inventory counting (T-A3): schema additions for concurrent-sales
 * counting sessions.
 *
 * - `inventory_countings` gains `block_sales`, `ambiguity_window_minutes`,
 *   `late_sales_flags` (written by C2), and `includes_zero_stock` (set by
 *   C1, read by C3 for auto-exit). The `chk_valid_scope_type` CHECK is
 *   dropped and re-added with the new `zone` scope value.
 * - `inventory_counting_items` gains device-vs-server count timestamps
 *   (`count_N_device_at` / `count_N_at_estimate`), `final_qty_as_of`
 *   (freezes the replay window end), `expected_qty_at_apply` (theoretical
 *   qty replayed forward to apply time), `opening_unit_cost` (written by
 *   D3, read by B3), `replay_audit` (jsonb — see
 *   App\Modules\Inventory\Application\DTO\ReplayAuditDto), and
 *   `flag_reasons` (jsonb array of CountingItemFlagReason values; the
 *   legacy scalar `flag_reason` column is untouched).
 *
 * Flag semantics (normative for B2/B3/B4/D3): `is_flagged=true` iff
 * `flag_reasons` contains a BLOCKING reason (`basket_window`,
 * `negative_at_apply`, `clock_skew`). `normalized_agreement` is
 * informational only and never sets `is_flagged`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_countings', function (Blueprint $table) {
            $table->boolean('block_sales')->default(false);
            $table->integer('ambiguity_window_minutes')->default(15);
            $table->jsonb('late_sales_flags')->nullable();
            $table->boolean('includes_zero_stock')->default(false);
        });

        Schema::table('inventory_counting_items', function (Blueprint $table) {
            $table->timestampTz('count_1_device_at')->nullable();
            $table->timestampTz('count_1_at_estimate')->nullable();
            $table->timestampTz('count_2_device_at')->nullable();
            $table->timestampTz('count_2_at_estimate')->nullable();
            $table->timestampTz('count_3_device_at')->nullable();
            $table->timestampTz('count_3_at_estimate')->nullable();
            $table->timestampTz('final_qty_as_of')->nullable();
            $table->decimal('expected_qty_at_apply', 15, 4)->nullable();
            $table->decimal('opening_unit_cost', 15, 6)->nullable();
            $table->jsonb('replay_audit')->nullable();
            $table->jsonb('flag_reasons')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE inventory_countings
                    DROP CONSTRAINT IF EXISTS chk_valid_scope_type;

                ALTER TABLE inventory_countings
                    ADD CONSTRAINT chk_valid_scope_type
                    CHECK (scope_type IN (
                        'product_location',
                        'product',
                        'location',
                        'category',
                        'full_inventory',
                        'zone'
                    ));
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE inventory_countings
                    DROP CONSTRAINT IF EXISTS chk_valid_scope_type;

                ALTER TABLE inventory_countings
                    ADD CONSTRAINT chk_valid_scope_type
                    CHECK (scope_type IN (
                        'product_location',
                        'product',
                        'location',
                        'category',
                        'full_inventory'
                    ));
            SQL);
        }

        Schema::table('inventory_counting_items', function (Blueprint $table) {
            $table->dropColumn([
                'count_1_device_at',
                'count_1_at_estimate',
                'count_2_device_at',
                'count_2_at_estimate',
                'count_3_device_at',
                'count_3_at_estimate',
                'final_qty_as_of',
                'expected_qty_at_apply',
                'opening_unit_cost',
                'replay_audit',
                'flag_reasons',
            ]);
        });

        Schema::table('inventory_countings', function (Blueprint $table) {
            $table->dropColumn([
                'block_sales',
                'ambiguity_window_minutes',
                'late_sales_flags',
                'includes_zero_stock',
            ]);
        });
    }
};
