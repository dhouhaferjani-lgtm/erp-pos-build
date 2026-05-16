<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 §7.5 + §13 — `pos_receipts` becomes a projection row of
     * `fiscal_events`.
     *
     * Adds two columns:
     *
     * 1. `canonical_bytes BYTEA NULL` — the verbatim canonical encoding of
     *    the `SALE_RECEIPT` fiscal event from the device. Nullable so legacy
     *    rows (created before the rebuild) remain valid.
     *
     * 2. `fiscal_event_id UUID NULL UNIQUE FK fiscal_events(id)` — the
     *    authoritative-event linkage anchor. One `pos_receipts` row per
     *    `SALE_RECEIPT` fiscal event. This UNIQUE is the idempotency guard
     *    `PosCoreReceiptProjection::apply()` (Task 21) relies on:
     *    `if (PosReceipt::where('fiscal_event_id', $event->id)->exists()) return;`
     *    is the safe-to-re-run guard for manual replay paths.
     *
     * Backward compatibility — the existing chain columns (`fiscal_hash`,
     * `previous_hash`, `chain_sequence`) are intentionally NOT dropped. They
     * become **mirror columns** of the authoritative `fiscal_events` row's
     * values and are populated by `PosCoreReceiptProjection` (Task 21) from
     * `$event->current_hash` / `$event->previous_hash` / `$event->sequence_number`
     * during projection. They are no longer advanced independently — the
     * fiscal-chain truth lives in `fiscal_events`.
     *
     * Multiple legacy rows with NULL `fiscal_event_id` are permitted: the
     * UNIQUE constraint accepts multiple NULLs (standard ANSI behavior on
     * PostgreSQL).
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            // BYTEA on PG / BLOB on SQLite — verbatim canonical encoding from
            // the device. Nullable for backward compatibility with rows that
            // pre-date the rebuild.
            $table->binary('canonical_bytes')->nullable();

            // Linkage to the authoritative `fiscal_events` row. Nullable for
            // backward compatibility; UNIQUE so one `pos_receipts` row maps to
            // exactly one fiscal event (idempotency anchor for Task 21).
            $table->uuid('fiscal_event_id')->nullable();
            $table->unique('fiscal_event_id', 'pos_receipts_fiscal_event_id_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // FK to `fiscal_events.id`. ON DELETE / ON UPDATE not declared —
            // PG's default NO ACTION blocks deletes (which is the invariant
            // we want; Task 8's BEFORE DELETE trigger on `fiscal_events`
            // forbids deletes outright upstream of any FK check).
            DB::statement(<<<'SQL'
                ALTER TABLE pos_receipts
                ADD CONSTRAINT pos_receipts_fiscal_event_id_fk
                FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_fiscal_event_id_fk');
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropUnique('pos_receipts_fiscal_event_id_unique');
            $table->dropColumn(['fiscal_event_id', 'canonical_bytes']);
        });
    }
};
