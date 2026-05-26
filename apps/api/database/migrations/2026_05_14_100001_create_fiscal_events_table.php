<?php

declare(strict_types=1);

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the server-side `fiscal_events` ledger.
     *
     * Spec §3.2 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
     * device-authored, server verify-only mirror. The table is the chain truth —
     * immutability triggers (Task 8) and projection state (Task 9) live elsewhere.
     */
    public function up(): void
    {
        Schema::create('fiscal_events', function (Blueprint $table): void {
            // Identity
            $table->uuid('id')->primary();

            // Tenancy + actors
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');
            $table->uuid('operator_id');

            // Event identity
            $table->string('event_type', 64);
            $table->smallInteger('event_version')->default(1);
            $table->string('signature_version', 64);

            // Chain coordinates
            $table->bigInteger('sequence_number');
            $table->timestampTz('event_time_device');
            $table->date('business_date');
            $table->string('chain_context', 32)->default('operational');
            $table->timestampTz('last_server_time_seen')->nullable();

            // Server ingestion timestamp — set explicitly by OutboxIngestor (§7, §10).
            // No default, no nullable: the ingestor must always populate it.
            $table->timestampTz('server_received_at');

            // Cross-event references
            $table->uuid('reference_event_id')->nullable();
            $table->uuid('reference_document_id')->nullable();
            $table->string('source_event_class', 255)->nullable();
            $table->uuid('source_event_id')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->jsonb('partner_identity_snapshot')->nullable();

            // Verbatim canonical bytes from the device (§4 canonical contract).
            $table->binary('canonical_bytes');

            // Hash chain — stored as lowercase hex CHAR(64).
            $table->char('previous_hash', 64);
            $table->char('current_hash', 64);

            // Signature object — never populated in Phase 1 (status defaults to 'not_required').
            $table->string('signature_status', 16)->default('not_required');
            $table->string('signature_algorithm', 64)->nullable();
            $table->text('signature_value')->nullable();
            $table->bigInteger('signature_counter')->nullable();
            $table->string('signature_provider', 64)->nullable();
            $table->uuid('signing_device_id')->nullable();
            $table->string('certificate_id', 128)->nullable();
            $table->text('signed_payload_ref')->nullable();
            $table->string('time_source_value', 64)->nullable();
            $table->string('time_format', 32)->nullable();
            $table->string('provider_transaction_id', 128)->nullable();

            // Integrity / quarantine (§8). Default 'verified'; ingestor flips to 'quarantined'
            // and only the resolver flips back (with resolved_at + resolved_by populated).
            $table->string('integrity_status', 24)->default('verified');
            $table->string('integrity_exception_class', 32)->nullable();
            $table->text('integrity_exception_reason')->nullable();
            $table->timestampTz('integrity_resolved_at')->nullable();
            $table->uuid('integrity_resolved_by')->nullable();

            // Derived structured payload (§7.6). Write-once after a successful parse.
            $table->jsonb('payload')->nullable();
            $table->string('payload_parse_status', 16)->default('pending');

            // Server-controlled creation timestamp.
            $table->timestampTz('created_at')->useCurrent();

            // Inline indexes that don't need partial / WHERE clauses.
            $table->unique(
                ['tenant_id', 'company_id', 'terminal_id', 'chain_context', 'sequence_number'],
                'fiscal_events_tenant_terminal_sequence_unique',
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Partial unique index — duplicate (class,id) only matters when both are set.
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX fiscal_events_source_event_unique
                    ON fiscal_events (source_event_class, source_event_id)
                    WHERE source_event_id IS NOT NULL
            SQL);

            // Partial indexes that target only the "interesting" subset of rows.
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_events_reference_event_idx
                    ON fiscal_events (reference_event_id)
                    WHERE reference_event_id IS NOT NULL
            SQL);
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_events_reference_document_idx
                    ON fiscal_events (reference_document_id)
                    WHERE reference_document_id IS NOT NULL
            SQL);
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_events_integrity_status_idx
                    ON fiscal_events (integrity_status)
                    WHERE integrity_status <> 'verified'
            SQL);

            // CHECK constraints — invariants enforced at the DB layer.
            DB::statement('ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_sequence_positive CHECK (sequence_number > 0)');
            DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_chain_context_allowed CHECK (chain_context IN ('operational', 'z_session', 'training_operational', 'training_z_session'))");
            DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_current_hash_format CHECK (current_hash ~ '^[0-9a-f]{64}\$')");
            DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_previous_hash_format CHECK (previous_hash ~ '^[0-9a-f]{64}\$')");
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_source_event_paired_null CHECK (
                    (source_event_class IS NULL AND source_event_id IS NULL)
                    OR (source_event_class IS NOT NULL AND source_event_id IS NOT NULL)
                )
            SQL);

            // Event type whitelist sourced from the enum so it can never drift from PHP.
            $allowed = FiscalEventType::checkConstraintList();
            DB::statement("ALTER TABLE fiscal_events ADD CONSTRAINT fiscal_events_event_type_allowed CHECK (event_type IN ({$allowed}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_events');
    }
};
