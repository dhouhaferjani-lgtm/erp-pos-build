<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('units_per_pack')
                ->nullable()
                ->after('unit_id')
                ->comment('Number of consumer units contained in one pack/box (e.g. 24 tablets per box)');

            $table->string('shelf_location', 100)
                ->nullable()
                ->after('units_per_pack')
                ->comment('Product-level default aisle/shelf identifier; per-location Inventory module can override');

            $table->decimal('reorder_point', 15, 4)
                ->nullable()
                ->after('shelf_location')
                ->comment('Quantity threshold below which a reorder should be triggered');

            $table->decimal('reorder_quantity', 15, 4)
                ->nullable()
                ->after('reorder_point')
                ->comment('Suggested quantity to order when reorder_point is reached');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['units_per_pack', 'shelf_location', 'reorder_point', 'reorder_quantity']);
        });
    }
};
