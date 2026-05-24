<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen the `fiscal_event_quarantine_class_phase1_allowed` CHECK constraint
     * to also permit `'malformed_envelope'` — Task 19 round-2 (T19-B4).
     *
     * Task 19's `OutboxIngestor` validates the inbound envelope's hash/UUID/
     * timestamp shape BEFORE attempting the `fiscal_events` insert (so a
     * malformed `current_hash` cannot surface as a PG CHECK violation deep
     * inside the DB layer). A malformed envelope is not a `sequence_conflict`
     * — the slot might be free — but it physically cannot enter
     * `fiscal_events` either (the same CHECK constraints that reject it on
     * insert make admission unsafe). It therefore routes to
     * `fiscal_event_quarantine` under the new
     * `IntegrityExceptionClass::MalformedEnvelope` value.
     *
     * The original Task 10 migration pinned the CHECK at a single value
     * (`'sequence_conflict'`) so reclassification could not silently widen
     * Phase 1's surface. Round-2 widens it by exactly one literal that
     * mirrors the enum; the partition between "admissible to ledger" and
     * "quarantined verbatim" stays explicit (both `sequence_conflict` and
     * `malformed_envelope` are non-admissible — see
     * `IntegrityExceptionClass::isAdmissibleToLedger()`).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // The CHECK only exists on PG (mirrors Task 10's pattern).
        }

        DB::statement('ALTER TABLE fiscal_event_quarantine DROP CONSTRAINT IF EXISTS fiscal_event_quarantine_class_phase1_allowed');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_event_quarantine
            ADD CONSTRAINT fiscal_event_quarantine_class_phase1_allowed
            CHECK (integrity_exception_class IN ('sequence_conflict', 'malformed_envelope'))
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE fiscal_event_quarantine DROP CONSTRAINT IF EXISTS fiscal_event_quarantine_class_phase1_allowed');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_event_quarantine
            ADD CONSTRAINT fiscal_event_quarantine_class_phase1_allowed
            CHECK (integrity_exception_class = 'sequence_conflict')
        SQL);
    }
};
