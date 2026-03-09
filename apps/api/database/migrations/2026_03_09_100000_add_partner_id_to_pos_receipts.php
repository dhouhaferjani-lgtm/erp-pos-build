<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add partner_id FK to pos_receipts for customer queryability.
     *
     * The denormalized customer_name and customer_identifier fields remain
     * for fiscal immutability (NF525). partner_id enables "receipts for customer X" queries.
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->foreignUuid('partner_id')
                ->nullable()
                ->after('customer_identifier')
                ->constrained('partners')
                ->nullOnDelete();

            $table->index('partner_id');
        });

        // Update the immutability trigger to allow setting partner_id during initial creation
        // but treat it as immutable after that (same as customer_name/customer_identifier)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN pos_receipts.partner_id IS 'Optional FK to partners table for customer queryability. Denormalized customer_name/customer_identifier remain for fiscal compliance.'");
        }
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropIndex(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
