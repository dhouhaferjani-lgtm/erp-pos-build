<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TODO: Re-enable when batch expiry module is fully implemented and tested
        // Schema::table('stock_reservations', function (Blueprint $table) {
        //     $table->foreignId('batch_id')->nullable()->after('product_id')
        //         ->constrained('product_batches')->nullOnDelete();

        //     $table->index('batch_id', 'idx_stock_reservations_batch');
        // });
    }

    public function down(): void
    {
        // Schema::table('stock_reservations', function (Blueprint $table) {
        //     $table->dropForeign(['batch_id']);
        //     $table->dropIndex('idx_stock_reservations_batch');
        //     $table->dropColumn('batch_id');
        // });
    }
};
