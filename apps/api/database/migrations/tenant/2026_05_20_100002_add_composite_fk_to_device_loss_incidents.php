<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Round-2 T32-P1: enforce tenant/company-scoped terminal integrity on
     * `device_loss_incidents`.
     *
     * Round-1 migration `2026_05_20_100001_create_device_loss_incidents_table`
     * declared three independent FKs:
     *   - device_loss_incidents.tenant_id     → tenants(id)
     *   - device_loss_incidents.company_id    → companies(id)
     *   - device_loss_incidents.terminal_id   → pos_terminals(id)
     *
     * Each FK only checks existence, so PG happily accepts an incident with
     * `tenant_id=A, company_id=A, terminal_id=<terminal-from-tenant-B>` — all
     * three UUIDs exist, but the incident's tenancy scope conflicts with the
     * terminal's real tenancy. For a forensic register that tracks the
     * authority context of a lost device, that's a silent cross-tenant
     * forgery surface.
     *
     * Fix: declare a composite FK on `(terminal_id, tenant_id, company_id)`
     * referencing `pos_terminals(id, tenant_id, company_id)`. PG requires the
     * referenced columns to carry a UNIQUE constraint; we add a redundant
     * UNIQUE on `pos_terminals(id, tenant_id, company_id)` (where `id` is
     * already the PK) so PG accepts the composite FK without modifying any
     * `pos_terminals` rows. The composite FK now rejects any cross-tenant
     * combination at the DB layer; the new PG-only test pins this.
     *
     * The existing simple `device_loss_incidents_terminal_id_fk` is dropped
     * before the composite FK is added — the composite FK supersedes it
     * (a row that satisfies the composite FK trivially satisfies the simple
     * `terminal_id → pos_terminals(id)` FK because `(id, tenant_id, company_id)`
     * is a stricter referent).
     *
     * SQLite skips the entire migration body — the test suite runs against
     * `:memory:` SQLite which doesn't enforce FKs anyway, and the PG-merge-
     * gate filter at .github/workflows/ci.yml runs the cross-tenant test
     * against real PG.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Add the redundant UNIQUE on pos_terminals(id, tenant_id, company_id)
        // so PG accepts a composite FK referencing those columns. `id` alone
        // is already the PK; adding the wider UNIQUE doesn't change any
        // semantics of `pos_terminals` but is required for the composite FK.
        // The unique constraint is named per the project convention.
        DB::statement(<<<'SQL'
            ALTER TABLE pos_terminals
            ADD CONSTRAINT pos_terminals_id_tenant_company_unique
            UNIQUE (id, tenant_id, company_id)
        SQL);

        // Drop the simple terminal_id FK — superseded by the composite FK.
        DB::statement(<<<'SQL'
            ALTER TABLE device_loss_incidents
            DROP CONSTRAINT device_loss_incidents_terminal_id_fk
        SQL);

        // Add the composite FK. ON DELETE NO ACTION matches the existing
        // FK semantics on this table — the incident IS the forensic record
        // of loss and must outlive cascade-delete attempts on its terminal.
        DB::statement(<<<'SQL'
            ALTER TABLE device_loss_incidents
            ADD CONSTRAINT device_loss_incidents_terminal_scope_fk
            FOREIGN KEY (terminal_id, tenant_id, company_id)
            REFERENCES pos_terminals (id, tenant_id, company_id)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Drop the composite FK first; then restore the simple FK; then drop
        // the unique. Order matters because the unique is required by the
        // composite FK.
        DB::statement(<<<'SQL'
            ALTER TABLE device_loss_incidents
            DROP CONSTRAINT device_loss_incidents_terminal_scope_fk
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE device_loss_incidents
            ADD CONSTRAINT device_loss_incidents_terminal_id_fk
            FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pos_terminals
            DROP CONSTRAINT pos_terminals_id_tenant_company_unique
        SQL);
    }
};
