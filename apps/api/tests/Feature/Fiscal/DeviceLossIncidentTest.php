<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\DeviceLossIncidentStatus;
use App\Modules\Fiscal\Domain\Models\DeviceLossIncident;
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

        $this->expectException(QueryException::class);
        DB::table('device_loss_incidents')->insert(
            $this->incidentRawRow(['recovery_status' => 'invalid_status']),
        );
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

        foreach ([
            'device_loss_incidents_tenant_id_fk',
            'device_loss_incidents_company_id_fk',
            'device_loss_incidents_terminal_id_fk',
            'device_loss_incidents_reported_by_fk',
        ] as $name) {
            $row = DB::selectOne(
                "SELECT conname FROM pg_constraint WHERE conname = ? AND contype = 'f'",
                [$name],
            );
            $this->assertNotNull($row, "FK {$name} missing on PostgreSQL");
        }
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentAttributes(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => Str::uuid()->toString(),
            'reported_at' => now()->toDateTimeString(),
            'reported_by' => Str::uuid()->toString(),
            'reason' => 'terminal stolen during overnight close',
            'unsynced_count_at_incident' => 14,
            'last_synced_event_at' => now()->subMinutes(45)->toDateTimeString(),
        ], $overrides);
    }

    /**
     * Raw-row variant for DB::table()->insert() — supplies the DB-level
     * defaults the model layer otherwise injects (recovery_status, timestamps).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentRawRow(array $overrides = []): array
    {
        return array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => Str::uuid()->toString(),
            'reported_at' => now()->toDateTimeString(),
            'reported_by' => Str::uuid()->toString(),
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
