<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Add if not exists - check first
            if (! Schema::hasColumn('stock_movements', 'reason')) {
                $table->string('reason', 50)->nullable()->after('movement_type');
            }
            if (! Schema::hasColumn('stock_movements', 'reference_type')) {
                $table->string('reference_type', 100)->nullable()->after('reference');
            }
            if (! Schema::hasColumn('stock_movements', 'reference_id')) {
                $table->uuid('reference_id')->nullable()->after('reference_type');
            }

            // Index for polymorphic lookup
            if (! Schema::hasIndex('stock_movements', 'idx_movements_reference')) {
                $table->index(['reference_type', 'reference_id'], 'idx_movements_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('idx_movements_reference');
            $table->dropColumn(['reason', 'reference_type', 'reference_id']);
        });
    }
};
