<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C2 Day 3 — add `menu_category_id` to `pos_receipt_lines` so the
     * Menu-tenant composite-ID context survives the wire roundtrip
     * and the refund flow can reconstruct the same composite the
     * cashier sold under.
     *
     * Day 1 (PR #107) packed `(sellable_id, menu_category_id)` into a
     * composite POSProduct id locally and `unpackCompositeIdsOnLines`
     * stripped the category context at the wire boundary so the
     * existing server schema accepted the payload. That left the
     * server blind to which menu category a line was sold under,
     * which the refund flow needs in order to recall the price the
     * cashier rang up (the same sellable can be priced differently
     * in two categories — e.g. "Coca" in "Drinks" vs "Lunch combo").
     *
     * Nullable: non-Menu tenants never populate this field, and
     * pre-C2 historical rows have no category context (legacy lines
     * gracefully degrade to no-category refund per kickoff Risk #4).
     */
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->uuid('menu_category_id')
                ->nullable()
                ->after('composite_item_id');

            $table->index('menu_category_id');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropIndex(['menu_category_id']);
            $table->dropColumn('menu_category_id');
        });
    }
};
