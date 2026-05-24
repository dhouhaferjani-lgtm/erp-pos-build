<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 §13 — Treasury-module integration migration.
     *
     * Adds two columns to `payments`:
     *
     * 1. `origin VARCHAR(32) NULL` — which surface authored the payment.
     *    Cast to `App\Modules\Treasury\Domain\Enums\PaymentOrigin` on the
     *    Eloquent model. Nullable so pre-migration rows (legacy) remain
     *    valid; the Task 22 `TreasuryReceiptBridge` projector tags new
     *    rows explicitly (`pos` when the projector ran from a `SALE_RECEIPT`
     *    fiscal event; other origins per the controller / API path).
     *
     * 2. `fiscal_event_id UUID NULL` — when this payment originates from a
     *    fiscal event (a `SALE_RECEIPT` projected by Task 22), the FK
     *    points at the authoritative row in `fiscal_events`. The direction
     *    is `payments → fiscal_events` — a Treasury module depending on
     *    the fiscal engine, which is the correct dependency direction per
     *    [SoT §13.6 / D16] (the asymmetric bounded-modules seam).
     *
     * No GL/allocation behavior changes — this is purely additive.
     *
     * No UNIQUE on `fiscal_event_id` here: unlike `pos_receipts` (where the
     * UNIQUE is the projection idempotency anchor for the POS-core
     * projector), a single fiscal event can have multiple Treasury
     * `Payment` rows (one per payment line — `ReceiptPayment` becomes one
     * Payment per tender). The idempotency anchor for the Treasury
     * projector lives on the (fiscal_event_id, payment_method_id, line)
     * tuple at projector-level, not on the table.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('origin', 32)->nullable();
            $table->uuid('fiscal_event_id')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE payments
                ADD CONSTRAINT payments_fiscal_event_id_fk
                FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
            SQL);

            // Index the projector-lookup hot path: when the
            // TreasuryReceiptBridge replays a fiscal event, it needs to know
            // whether any Payment rows already linked to that event id
            // (idempotency tail check). Partial — most legacy rows have
            // NULL fiscal_event_id and don't belong in this index.
            DB::statement(<<<'SQL'
                CREATE INDEX payments_fiscal_event_id_idx
                    ON payments (fiscal_event_id)
                    WHERE fiscal_event_id IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payments_fiscal_event_id_idx');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_fiscal_event_id_fk');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'fiscal_event_id']);
        });
    }
};
