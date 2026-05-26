<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `change_due` (cash change returned) to pos_receipts.
 *
 * Historical rows will have NULL — ReceiptPdfService falls back to the
 * `totalPaid - total` computation for backwards compatibility.
 *
 * Rationale: the POS client already sends `change_due` in the sync payload,
 * but the server silently dropped it, leading to "0 change due" on every
 * printed thermal receipt (BG6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->decimal('change_due', 12, 3)
                ->nullable()
                ->after('total')
                ->comment('Cash returned to customer. NULL on legacy rows; use totalPaid-total fallback.');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn('change_due');
        });
    }
};
