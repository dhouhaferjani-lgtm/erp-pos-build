<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('pos_receipt_line_batch_allocations', function (Blueprint $table): void {
            $table->uuid('variant_id')->nullable()->after('receipt_line_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_receipt_line_batch_allocations ADD CONSTRAINT pos_receipt_line_batch_allocations_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipt_line_batch_allocations DROP CONSTRAINT IF EXISTS pos_receipt_line_batch_allocations_variant_id_foreign');
        }

        Schema::table('pos_receipt_line_batch_allocations', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });
    }
};
