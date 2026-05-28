<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds quantity_received column for tracking partial goods receipt on purchase orders.
     * This enables:
     * - Partial receipts over multiple deliveries
     * - WAC updates per receipt batch
     * - 3-way matching (PO ↔ GRN ↔ Invoice)
     */
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->decimal('quantity_received', 15, 4)
                ->default('0.0000')
                ->after('quantity')
                ->comment('Quantity received for purchase order lines (supports partial receipts)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_received');
        });
    }
};
