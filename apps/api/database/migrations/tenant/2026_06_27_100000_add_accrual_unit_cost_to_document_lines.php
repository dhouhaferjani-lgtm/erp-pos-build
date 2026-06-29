<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3 guard: records the receipt-time 408 accrual basis per PO line.
 *
 * Set once by GoodsReceiptService::processReceiptLines() from
 * (landed_unit_cost ?? unit_price) at the moment the GR-IR credit is posted.
 * Immutable after that: SupplierInvoicePostingService::post() asserts that
 * the clearing basis equals this value before creating the clearing JE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            // scale 6 matches landed_unit_cost — carries sub-cent precision for WAC.
            $table->decimal('accrual_unit_cost', 15, 6)->nullable()->after('landed_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn('accrual_unit_cost');
        });
    }
};
