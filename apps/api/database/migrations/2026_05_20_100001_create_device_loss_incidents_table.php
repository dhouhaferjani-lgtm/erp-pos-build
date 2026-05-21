<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the server-side `device_loss_incidents` register (Task 32).
     *
     * Spec §12 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`):
     * device authority is not survivable without off-device conservation.
     * The conservation control itself is delivered on the device by
     * `OffDeviceDurabilityService` (apps/pos/src/lib/fiscal/OffDeviceDurabilityService.ts);
     * this table is the SERVER-SIDE audit trail for every terminal-loss
     * incident so the recovery workflow has a count of unsynced fiscal
     * events at the moment of loss and a lifecycle status the recovery
     * service advances.
     *
     * Unlike `fiscal_events`, this table is MUTABLE — the recovery workflow
     * writes `recovery_status` transitions (`reported → recovering → resolved
     * | unrecoverable`) and the `updated_at` timestamp tracks them. The
     * `recovery_status` whitelist is pinned at the DB layer via a CHECK
     * constraint (Task 9 / Task 10 standing pattern); the model excludes the
     * column from `$fillable` so transitions go through explicit service
     * code, never mass-assignment.
     *
     * FKs to `tenants(id)` / `companies(id)` / `pos_terminals(id)` are
     * declared via raw SQL on PostgreSQL only; on SQLite we skip the FKs
     * (and the CHECK + named indexes) so the in-memory test suite stays
     * driver-portable. The PG-merge-gate filter at .github/workflows/ci.yml
     * exercises the PG-only assertions.
     */
    public function up(): void
    {
        Schema::create('device_loss_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Tenancy + scoping — captured at incident time so a stolen
            // terminal's tenancy is preserved even if the terminal row
            // is later archived.
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');

            // Incident metadata.
            $table->timestampTz('reported_at');
            // `reported_by` is NULL when the incident is auto-detected from a
            // heartbeat-missing alert rather than an operator report (spec §12
            // reads "device-loss incident register" without mandating human
            // reporters).
            $table->uuid('reported_by')->nullable();
            $table->text('reason');

            // Conservation telemetry captured at the moment of loss — the
            // recovery workflow uses these to size the off-device archive
            // restore + to file the fiscal anomaly report when the incident
            // closes as `unrecoverable`.
            $table->integer('unsynced_count_at_incident');
            $table->timestampTz('last_synced_event_at')->nullable();

            // Lifecycle (DeviceLossIncidentStatus). The default at the DB
            // layer is 'reported' so the model's $fillable can omit this
            // column safely — insert-time mass-assignment cannot bypass the
            // initial state, and post-insert transitions go through
            // setAttribute() / forceFill() from auditable service code only.
            $table->string('recovery_status', 32)->default('reported');

            // Mutable row → standard Eloquent timestamps with DB-level
            // defaults so direct DB::table()->insert() works without
            // manually setting them.
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Lifecycle whitelist pinned at the DB layer — extending requires a
            // new migration that widens the CHECK in lockstep with the enum
            // (Task 9 / Task 10 standing pattern).
            DB::statement(<<<'SQL'
                ALTER TABLE device_loss_incidents
                ADD CONSTRAINT device_loss_incidents_recovery_status_allowed
                CHECK (recovery_status IN ('reported', 'recovering', 'resolved', 'unrecoverable'))
            SQL);

            // FKs — declared via raw SQL with explicit constraint names so the
            // PG-merge-gate test asserts them by name. ON DELETE NO ACTION
            // (implicit, the PG default) is intentional: we never want to
            // cascade-delete an incident register row when its terminal or
            // tenant is archived, since the incident is the forensic
            // record-of-loss.
            DB::statement(<<<'SQL'
                ALTER TABLE device_loss_incidents
                ADD CONSTRAINT device_loss_incidents_tenant_id_fk
                FOREIGN KEY (tenant_id) REFERENCES tenants(id)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE device_loss_incidents
                ADD CONSTRAINT device_loss_incidents_company_id_fk
                FOREIGN KEY (company_id) REFERENCES companies(id)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE device_loss_incidents
                ADD CONSTRAINT device_loss_incidents_terminal_id_fk
                FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE device_loss_incidents
                ADD CONSTRAINT device_loss_incidents_reported_by_fk
                FOREIGN KEY (reported_by) REFERENCES users(id)
            SQL);

            // Admin browse hot path — per-tenant per-terminal incident list
            // (tenant-leading per the project's tenant-isolation convention
            // — pos-stab cluster + Treasury company-scope session).
            DB::statement(<<<'SQL'
                CREATE INDEX device_loss_incidents_tenant_terminal_idx
                    ON device_loss_incidents (tenant_id, terminal_id)
            SQL);

            // Recovery worker hot path — scan OPEN incidents (reported /
            // recovering) ordered by `reported_at`. Partial so the long tail
            // of resolved / unrecoverable rows stays out of the index, mirror-
            // ing the Task 9 partial-index pattern for the projection worker.
            DB::statement(<<<'SQL'
                CREATE INDEX device_loss_incidents_open_status_idx
                    ON device_loss_incidents (recovery_status, reported_at)
                    WHERE recovery_status IN ('reported', 'recovering')
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_loss_incidents');
    }
};
