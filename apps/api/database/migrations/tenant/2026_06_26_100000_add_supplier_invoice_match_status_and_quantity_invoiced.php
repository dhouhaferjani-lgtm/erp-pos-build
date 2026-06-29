<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds two columns for the supplier-invoice / 3-way matching feature (C1):
     *
     * 1. documents.match_status (nullable string)
     *    Only supplier_invoice documents carry a match status; all other
     *    document types stay NULL. The enum cast + application layer enforce
     *    the allowed set (SupplierInvoiceMatchStatus). No PG CHECK constraint
     *    is added here to keep the migration simple and SQLite-compatible for
     *    feature tests; the cast ensures only valid enum values are persisted.
     *
     * 2. document_lines.quantity_invoiced (decimal 15,4, default 0)
     *    Cumulative quantity already invoiced against a purchase-order line.
     *    Mirrors quantity_received (added 2025-12-13). The C2 matcher will
     *    update this column as supplier invoices are processed.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('match_status')->nullable()->after('payload')
                ->comment('3-way match status for supplier invoices (SupplierInvoiceMatchStatus); null for all other document types');
        });

        Schema::table('document_lines', function (Blueprint $table): void {
            $table->decimal('quantity_invoiced', 15, 4)
                ->default('0.0000')
                ->after('quantity_received')
                ->comment('Quantity already invoiced for purchase-order lines (supports partial invoicing in 3-way matching)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn('quantity_invoiced');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('match_status');
        });
    }
};
