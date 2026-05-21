<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\DeviceLossIncidentStatus;
use App\Modules\Fiscal\Domain\Models\DeviceLossIncident;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 32 — `device_loss_incidents` server-side register (spec §12).
 *
 * Spec §12: device authority is not survivable without off-device conservation.
 * The device-loss incident register records each terminal-loss event so the
 * recovery workflow has an audit trail: incident_id, terminal, tenant, reported
 * time + operator, free-form reason, captured unsynced_count + last synced
 * event timestamp at the moment of incident, and a `recovery_status` lifecycle.
 *
 * Schema invariants pinned here:
 *   - Every column the operator workflow + recovery jobs will read/write exists.
 *   - The `recovery_status` CHECK constraint pins the lifecycle whitelist at
 *     the DB layer (mirrors the Task 9 / Task 10 pattern).
 *   - The hot-path indexes for the admin browse + recovery worker exist.
 *   - FK constraints on tenant_id / company_id / terminal_id block orphans
 *     on PostgreSQL.
 *
 * The PG-only assertions (CHECK + FK + named-index) skip on SQLite where the
 * schema is built via Schema::create() only. The portable assertions (column
 * presence, enum cast, fillable-vs-lifecycle boundary discipline) run on both
 * drivers.
 */
final class DeviceLossIncidentTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    /**
     * Seed real referenced rows so the FK constraints on `device_loss_incidents`
     * are satisfied. Round-1 used random UUIDs and silently passed on SQLite
     * (FKs not enforced) — on PG the raw-insert tests would FK-violate before
     * reaching the CHECK constraint, which is exactly what the §12 register
     * test must pin. See round-2 BLOCKER T32-B1.
     *
     * Seeded once per test (RefreshDatabase resets between tests). Tests that
     * need cross-tenant terminals (round-2 T32-P1) seed additional rows
     * locally rather than mutating the shared scope.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Operator',
        ]);
        $this->operatorId = $user->id;
    }

    public function test_table_has_all_register_columns(): void
    {
        $this->assertTrue(Schema::hasTable('device_loss_incidents'));
        foreach ([
            'id',
            'tenant_id',
            'company_id',
            'terminal_id',
            'reported_at',
            'reported_by',
            'reason',
            'unsynced_count_at_incident',
            'last_synced_event_at',
            'recovery_status',
            'created_at',
            'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('device_loss_incidents', $col),
                "missing {$col}",
            );
        }
    }

    public function test_device_loss_incident_can_be_registered_with_defaults(): void
    {
        $attrs = $this->incidentAttributes();
        $incident = DeviceLossIncident::create($attrs);

        $this->assertDatabaseHas('device_loss_incidents', ['id' => $incident->id]);
        $fresh = DeviceLossIncident::findOrFail($incident->id);
        $this->assertSame(DeviceLossIncidentStatus::Reported, $fresh->recovery_status);
        $this->assertSame($attrs['unsynced_count_at_incident'], $fresh->unsynced_count_at_incident);
    }

    public function test_reported_by_is_nullable(): void
    {
        $attrs = $this->incidentAttributes(['reported_by' => null]);
        $incident = DeviceLossIncident::create($attrs);
        $this->assertNull($incident->refresh()->reported_by);
    }

    public function test_last_synced_event_at_is_nullable(): void
    {
        // Spec §12: if a terminal is lost BEFORE the first sync, there is no
        // prior synced event timestamp to record. The column must accept NULL.
        $attrs = $this->incidentAttributes(['last_synced_event_at' => null]);
        $incident = DeviceLossIncident::create($attrs);
        $this->assertNull($incident->refresh()->last_synced_event_at);
    }

    public function test_recovery_status_must_be_valid_enum_value_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        // Round-2 T32-B1: assert SQLSTATE 23514 (CHECK violation) + constraint
        // name so the test proves the CHECK fired, not an unrelated FK. Round-1
        // accepted any QueryException, which could mask FK orphans.
        try {
            DB::table('device_loss_incidents')->insert(
                $this->incidentRawRow(['recovery_status' => 'invalid_status']),
            );
            $this->fail('Expected QueryException for CHECK violation, but insert succeeded');
        } catch (QueryException $e) {
            $this->assertSame(
                '23514',
                $e->getCode(),
                'expected PG SQLSTATE 23514 (CHECK violation); got '.$e->getCode().' with message: '.$e->getMessage(),
            );
            $this->assertStringContainsString(
                'device_loss_incidents_recovery_status_allowed',
                $e->getMessage(),
                'CHECK violation must name the constraint device_loss_incidents_recovery_status_allowed',
            );
        }
    }

    public function test_recovery_status_check_allows_every_enum_value_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        foreach (DeviceLossIncidentStatus::cases() as $case) {
            $row = $this->incidentRawRow(['recovery_status' => $case->value]);
            DB::table('device_loss_incidents')->insert($row);
            $this->assertDatabaseHas('device_loss_incidents', [
                'id' => $row['id'],
                'recovery_status' => $case->value,
            ]);
        }
    }

    public function test_recovery_status_check_constraint_is_named_per_convention_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'c'",
            ['device_loss_incidents_recovery_status_allowed'],
        );
        $this->assertNotNull(
            $row,
            'CHECK device_loss_incidents_recovery_status_allowed missing on PostgreSQL',
        );
    }

    public function test_tenant_terminal_index_exists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            ['device_loss_incidents_tenant_terminal_idx'],
        );
        $this->assertNotNull(
            $row,
            'Index device_loss_incidents_tenant_terminal_idx missing on PostgreSQL',
        );
        $this->assertStringContainsString('tenant_id', $row->indexdef);
        $this->assertStringContainsString('terminal_id', $row->indexdef);
    }

    public function test_recovery_status_partial_index_exists_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            ['device_loss_incidents_open_status_idx'],
        );
        $this->assertNotNull(
            $row,
            'Partial index device_loss_incidents_open_status_idx missing on PostgreSQL',
        );
        $this->assertStringContainsString('recovery_status', $row->indexdef);
        $this->assertStringContainsString('reported_at', $row->indexdef);
        // The hot path scans OPEN incidents only — the long tail of resolved /
        // unrecoverable rows must stay out of the index.
        $this->assertStringContainsString('reported', $row->indexdef);
    }

    public function test_tenant_terminal_company_fks_exist_on_postgres(): void
    {
        $this->skipUnlessPostgres();

        // Round-2 T32-P1: `device_loss_incidents_terminal_id_fk` (the round-1
        // simple FK on terminal_id alone) is dropped + replaced by the
        // composite FK `device_loss_incidents_terminal_scope_fk` — covered in
        // `test_composite_terminal_scope_fk_exists_on_postgres`. The other
        // three FKs remain unchanged.
        foreach ([
            'device_loss_incidents_tenant_id_fk',
            'device_loss_incidents_company_id_fk',
            'device_loss_incidents_reported_by_fk',
        ] as $name) {
            $row = DB::selectOne(
                "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
                [$name],
            );
            $this->assertNotNull($row, "FK {$name} missing on PostgreSQL");
        }
    }

    public function test_cross_tenant_terminal_id_is_rejected_by_composite_fk_on_postgres(): void
    {
        // Round-2 T32-P1: the composite FK
        // `device_loss_incidents_terminal_scope_fk` rejects any incident whose
        // (tenant_id, company_id) pair doesn't match the referenced terminal's
        // (tenant_id, company_id). Round-1 had three independent FKs and would
        // accept such a row silently.
        $this->skipUnlessPostgres();

        // Seed a second tenant+company+terminal triple. Reuse the existing
        // company's location for the new terminal so the location FK is
        // satisfied (location_id is not part of the cross-tenant test).
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        // Build an incident with $this->tenantId / $this->companyId (tenant A)
        // but use $otherTerminal->id from tenant B. PG must reject this.
        try {
            DB::table('device_loss_incidents')->insert(
                $this->incidentRawRow(['terminal_id' => $otherTerminal->id]),
            );
            $this->fail(
                'Expected QueryException for cross-tenant composite FK violation, but insert succeeded',
            );
        } catch (QueryException $e) {
            // PG returns SQLSTATE 23503 for foreign-key violations.
            $this->assertSame(
                '23503',
                $e->getCode(),
                'expected PG SQLSTATE 23503 (FK violation); got '.$e->getCode().' with message: '.$e->getMessage(),
            );
            $this->assertStringContainsString(
                'device_loss_incidents_terminal_scope_fk',
                $e->getMessage(),
                'FK violation must name the composite constraint device_loss_incidents_terminal_scope_fk',
            );
        }
    }

    public function test_composite_terminal_scope_fk_exists_on_postgres(): void
    {
        // Round-2 T32-P1: the composite FK exists with the expected name. The
        // round-1 simple `device_loss_incidents_terminal_id_fk` is dropped and
        // replaced by `device_loss_incidents_terminal_scope_fk` covering
        // (terminal_id, tenant_id, company_id) → pos_terminals(id, tenant_id, company_id).
        $this->skipUnlessPostgres();

        $row = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
            ['device_loss_incidents_terminal_scope_fk'],
        );
        $this->assertNotNull(
            $row,
            'Composite FK device_loss_incidents_terminal_scope_fk missing on PostgreSQL',
        );

        // The simple FK that round-1 introduced should be gone — it was
        // superseded by the composite FK (round-2 migration drops it).
        $supersededFk = DB::selectOne(
            "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
            ['device_loss_incidents_terminal_id_fk'],
        );
        $this->assertNull(
            $supersededFk,
            'Simple FK device_loss_incidents_terminal_id_fk should be dropped (superseded by composite FK)',
        );
    }

    public function test_lifecycle_status_is_not_mass_assignable(): void
    {
        // Boundary discipline (Task 9 / Task 10 standing pattern): the lifecycle
        // column `recovery_status` MUST NOT be mass-assignable — state transitions
        // (reported → recovering → resolved | unrecoverable) belong to the
        // recovery service, not external request payloads. The DB default
        // ('reported') seeds the initial value; only explicit setAttribute() /
        // forceFill() can change it.
        $attrs = $this->incidentAttributes(['recovery_status' => 'resolved']);
        $incident = DeviceLossIncident::create($attrs);
        $this->assertSame(
            DeviceLossIncidentStatus::Reported,
            $incident->refresh()->recovery_status,
            'recovery_status must NOT honor mass-assignment from create()',
        );
    }

    /**
     * Attribute set for `DeviceLossIncident::create()`. Round-2 T32-B1: uses
     * seeded tenant/company/terminal/user refs so the FK constraints on PG
     * are satisfied; SQLite ignores FKs so the test is portable either way.
     * `reported_by` defaults to the seeded operator's UUID; override with
     * NULL when the test targets the nullable-FK code path.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentAttributes(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'reported_at' => now()->toDateTimeString(),
            'reported_by' => $this->operatorId,
            'reason' => 'terminal stolen during overnight close',
            'unsynced_count_at_incident' => 14,
            'last_synced_event_at' => now()->subMinutes(45)->toDateTimeString(),
        ], $overrides);
    }

    /**
     * Raw-row variant for DB::table()->insert() — supplies the DB-level
     * defaults the model layer otherwise injects (recovery_status, timestamps).
     *
     * Round-2 T32-B1: identity columns now use seeded FK targets (not random
     * UUIDs) so PG's FK constraints don't fire before the CHECK / cross-tenant
     * tests can validate what they actually mean to validate.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentRawRow(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'reported_at' => now()->toDateTimeString(),
            // `reported_by` is nullable; NULL is the safer default for the
            // raw-row helper because most of the PG-only tests don't care
            // about the operator identity.
            'reported_by' => null,
            'reason' => 'terminal lost in transit',
            'unsynced_count_at_incident' => 0,
            'last_synced_event_at' => null,
            'recovery_status' => 'reported',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides);
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FK + CHECK + named-index DDL only enforced on PostgreSQL');
        }
    }
}
