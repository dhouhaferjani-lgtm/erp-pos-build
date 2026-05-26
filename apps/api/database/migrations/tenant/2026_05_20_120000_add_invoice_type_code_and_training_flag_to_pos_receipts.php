<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pass 2A.PHP.1 (synthesis v5 §8.A) — add the two projector-consumed
     * columns the new 27-key canonical SALE_RECEIPT payload requires.
     *
     * 1. `invoice_type_code VARCHAR(16) NOT NULL DEFAULT 'SALE'` — the
     *    canonical payload enum domain is {SALE, REFUND, VOID, TRAINING}.
     *    Receipt queries that filter refund/void/training paths need a
     *    physical column (every NF525 daily total + DSFinV-K daily Z report
     *    must exclude TRAINING). Defaults to SALE so backfill of legacy
     *    rows (`fiscal_event_id IS NULL`) keeps current semantics.
     *
     * 2. `training_flag BOOLEAN NOT NULL DEFAULT FALSE` — denormalized
     *    boolean derived from `invoice_type_code == 'TRAINING'`. Kept
     *    separately because:
     *      - Every reporting query filters out training (mandated by NF525
     *        "NON VALABLE POUR ENCAISSEMENT" + DSFinV-K Trainingsbuchung).
     *      - The index path is `WHERE training_flag = FALSE` not
     *        `WHERE invoice_type_code != 'TRAINING'`.
     *    Pass 2A.PHP.2's projector writes both columns on INSERT (the
     *    `prevent_receipt_modification()` trigger blocks subsequent
     *    UPDATEs; new columns sit OUTSIDE the void-whitelist by design —
     *    they are sealed-at-INSERT data).
     *
     * Backfill: existing rows get DEFAULT 'SALE' / FALSE. Pre-existing
     * `pos_receipts` rows were all SALE-type sales by construction
     * (refunds went through `is_voided=true` + null `void_*` columns at
     * the legacy path; that path doesn't migrate to TRAINING/REFUND).
     *
     * Down migration drops both columns.
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->string('invoice_type_code', 16)->default('SALE');
            $table->boolean('training_flag')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn(['invoice_type_code', 'training_flag']);
        });
    }
};
