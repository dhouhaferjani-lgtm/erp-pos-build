<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_batch_tracking')->default(false)->after('is_active_for_ecommerce');
            $table->integer('default_shelf_life_days')->nullable()->after('requires_batch_tracking')
                ->comment('Default shelf life in days for auto-calculating expiry dates');

            $table->index('requires_batch_tracking', 'idx_products_batch_tracking');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_batch_tracking');
            $table->dropColumn(['requires_batch_tracking', 'default_shelf_life_days']);
        });
    }
};
