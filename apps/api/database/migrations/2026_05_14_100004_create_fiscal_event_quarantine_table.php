<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the server-side `fiscal_event_quarantine` non-admissible-envelope
     * partition.
     *
     * Spec §8 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
     * `sequence_conflict` envelopes physically cannot enter `fiscal_events`
     * (UNIQUE on (tenant, terminal, sequence) is already claimed by a
     * different event). They go here verbatim — full canonical bytes + raw
     * typed envelope + mirrored query-critical metadata — so the verifier
     * (§15) and JET export (§16) can render the incident with the same
     * fidelity as an admitted `fiscal_events` row.
     *
     * Unlike `fiscal_events`, this table is mutable — the resolution flow
     * writes `resolved_at` / `resolved_by` when an admin clears the incident.
     * It carries NO immutability trigger.
     */
    public function up(): void
    {
        Schema::create('fiscal_event_quarantine', function (Blueprint $table): void {
            // Identity
            $table->uuid('id')->primary();

            // Tenancy + actors — mirrored from the conflicting envelope.
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');
            $table->uuid('operator_id');

            // The device-claimed fiscal_events.id, retained for forensics. Not a FK —
            // the envelope was rejected before it could land in `fiscal_events`.
            $table->uuid('envelope_event_id');

            // Event identity
            $table->string('event_type', 64);
            $table->smallInteger('event_version');
            $table->string('signature_version', 64);

            // Chain coordinates of the conflicting envelope (the slot it tried to claim).
            $table->bigInteger('claimed_sequence_number');
            $table->timestampTz('event_time_device');
            $table->date('business_date');
            $table->string('chain_context', 32)->default('operational');
            $table->timestampTz('last_server_time_seen')->nullable();

            // Cross-event references — preserved so quarantine incidents can be cross-walked
            // back to the originating source row if the resolver needs to investigate.
            $table->uuid('reference_event_id')->nullable();
            $table->uuid('reference_document_id')->nullable();
            $table->string('source_event_class', 255)->nullable();
            $table->uuid('source_event_id')->nullable();

            // Hash chain — stored as lowercase hex CHAR(64) matching `fiscal_events`.
            $table->char('previous_hash', 64);
            $table->char('current_hash', 64);

            // Conserved envelope — the verbatim payload, plus the full transport envelope
            // (typed). `canonical_bytes` is what the device signed; `raw_envelope` is what
            // the server received before parsing.
            $table->binary('canonical_bytes');
            $table->jsonb('raw_envelope');
            $table->string('payload_parse_status', 16)->nullable();

            // Incident metadata.
            //
            // `integrity_exception_class` is constrained to the Phase 1 single value
            // (`'sequence_conflict'`) via a CHECK constraint declared below on PG. The
            // model adds an `IntegrityExceptionClass` enum cast on read.
            $table->string('integrity_exception_class', 32);
            $table->text('integrity_exception_reason');

            // The `fiscal_events` row already occupying the slot — the row that won the
            // sequence race. Not declared as a FK so a future export-and-purge of
            // resolved quarantine rows doesn't cascade-trip on retained fiscal events,
            // and so the quarantine record is self-contained.
            $table->uuid('conflicting_event_id');

            $table->timestampTz('server_received_at');

            // Resolution stamps — written when admin clears the incident.
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();

            // Server-controlled creation timestamp.
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Phase 1 single-value whitelist: only `sequence_conflict` envelopes ever
            // land here. Future phases that add new non-admissible classes will need to
            // extend this list (and the IntegrityExceptionClass::isAdmissibleToLedger()
            // partition simultaneously). Pinning it at the DB layer prevents drift.
            //
            // Phase 2 follow-up: once this CHECK widens beyond a single value, Task 8's
            // BEFORE UPDATE trigger pattern becomes mandatory on this table to forbid
            // reclassification post-insert (the Task 8 round-2 BLOCKER lesson). Today
            // the single-value CHECK forecloses reclassification implicitly — there is
            // only one valid value to set this column to, and it is the value already
            // present. The model also excludes `integrity_exception_class` from
            // `$fillable`, so mass-assignment cannot reach it; only `forceFill()` /
            // `setAttribute()` from explicit code can, which is auditable.
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_event_quarantine
                ADD CONSTRAINT fiscal_event_quarantine_class_phase1_allowed
                CHECK (integrity_exception_class = 'sequence_conflict')
            SQL);

            // Hash-format CHECKs mirror the Task 7 fiscal_events pattern — the
            // conserved hashes must remain lowercase 64-hex even though they describe
            // an incident envelope, so the verifier (§15) can reconcile them against
            // the chain the device built.
            DB::statement(
                "ALTER TABLE fiscal_event_quarantine
                 ADD CONSTRAINT fiscal_event_quarantine_current_hash_format
                 CHECK (current_hash ~ '^[0-9a-f]{64}\$')"
            );
            DB::statement(
                "ALTER TABLE fiscal_event_quarantine
                 ADD CONSTRAINT fiscal_event_quarantine_previous_hash_format
                 CHECK (previous_hash ~ '^[0-9a-f]{64}\$')"
            );

            // Hot-path index for the admin-resolution view + verifier (§15): scan
            // unresolved incidents. Partial — keeps the long tail of resolved rows
            // out of the hot index. Leading column is `tenant_id` to align with
            // Task 7's `fiscal_events_tenant_terminal_sequence_unique` and the
            // project's tenant-leading convention, so both query shapes
            // (per-terminal verifier; per-tenant admin browse) prune efficiently.
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_event_quarantine_unresolved_idx
                    ON fiscal_event_quarantine (tenant_id, terminal_id, chain_context, claimed_sequence_number)
                    WHERE resolved_at IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_event_quarantine');
    }
};
