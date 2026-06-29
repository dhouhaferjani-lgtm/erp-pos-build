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
     * Adds documents.supplier_credit_note_reason (nullable string), the explicit
     * reason that drives the supplier-credit-note GL + quantity_invoiced matrix
     * (D1). Only supplier_credit_note documents carry a value
     * (SupplierCreditNoteReason: price_adjustment | goods_return); all other
     * document types stay NULL. The enum cast on the Document model enforces the
     * allowed set; no PG CHECK constraint is added to keep the migration simple
     * and SQLite-compatible for feature tests.
     *
     * NOT reused: the existing documents.credit_note_reason column carries the
     * CUSTOMER-side CreditNoteReason enum — a different value set with different
     * semantics — so the supplier reason gets its own column.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('supplier_credit_note_reason')->nullable()->after('match_status')
                ->comment('Supplier credit-note reason (SupplierCreditNoteReason); null for all other document types');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('supplier_credit_note_reason');
        });
    }
};
