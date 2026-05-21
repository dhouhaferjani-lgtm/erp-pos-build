<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the server-side `fiscal_event_projections` table.
     *
     * Spec §7.5 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
     * one row per (fiscal_event, projector). The OutboxIngestor (Task 19) seeds a row
     * per active projector when an event is ingested; ApplyFiscalEventProjectionJob
     * (Task 23) advances `attempts` / `last_error` / `last_attempted_at` and flips
     * `projection_status` to `applied` or `dead_lettered`.
     *
     * Unlike `fiscal_events`, this table is mutable — it is NOT chain truth and
     * carries NO immutability trigger. The idempotency contract is the composite
     * UNIQUE on (fiscal_event_id, projector_name).
     *
     * FK to `fiscal_events(id)` is declared via raw SQL on PostgreSQL only; on
     * SQLite we skip the FK so the in-memory test suite stays driver-portable.
     */
    public function up(): void
    {
        Schema::create('fiscal_event_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The event being projected. NOT NULL — every projection row references
            // exactly one fiscal event. FK constraint added below for PG.
            $table->uuid('fiscal_event_id');

            // Projector identity (e.g. 'pos_core_receipt', 'treasury_receipt_bridge').
            // String, not enum, because the registry is extensible at runtime per
            // §3 (pluggable projectors) — adding a projector cannot require a
            // schema migration.
            $table->string('projector_name', 64);

            // Lifecycle: pending | running | applied | dead_lettered
            // (App\Modules\Fiscal\Domain\Enums\ProjectionStatus).
            $table->string('projection_status', 20)->default('pending');

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();

            // Mutable row → standard Eloquent timestamps with DB-level defaults
            // so direct DB::table()->insert() works without manually setting them.
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Idempotency key (§7.5): a given projector runs at most once per event.
            // Portable composite UNIQUE — enforced on every driver.
            $table->unique(
                ['fiscal_event_id', 'projector_name'],
                'fiscal_event_projections_event_projector_unique',
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // FK → fiscal_events(id). Cascading on delete is intentionally NOT set —
            // fiscal_events is append-only (Task 8 immutability triggers block deletes
            // anyway). PG's default ON DELETE NO ACTION is implicit and correct:
            // delete-blocking is the invariant we want. Note that NO ACTION is
            // deferrable (checked at statement end) whereas RESTRICT is non-deferrable;
            // the distinction is immaterial here since Task 8's BEFORE DELETE trigger
            // on fiscal_events forbids deletes outright upstream of any FK check.
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_event_projections
                ADD CONSTRAINT fiscal_event_projections_fiscal_event_id_fk
                FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)
            SQL);

            // Partial index for the worker dispatcher hot path: it only ever scans
            // non-terminal rows. Indexing the full status column would waste space
            // on the long tail of `applied` rows (most of the table over time).
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_event_projections_status_pending_idx
                    ON fiscal_event_projections (projection_status)
                    WHERE projection_status IN ('pending', 'running')
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_event_projections');
    }
};
