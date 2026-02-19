<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds treasury_payment_id foreign key to pos_receipt_payments to link
     * POS receipt payments with Treasury payment records and General Ledger entries.
     */
    public function up(): void
    {
        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->foreignUuid('treasury_payment_id')
                ->nullable()
                ->after('transaction_reference')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->index('treasury_payment_id');
        });

        // PostgreSQL-specific comment
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('COMMENT ON COLUMN pos_receipt_payments.treasury_payment_id IS \'Link to Treasury Payment for GL integration\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->dropForeign(['treasury_payment_id']);
            $table->dropColumn('treasury_payment_id');
        });
    }
};
