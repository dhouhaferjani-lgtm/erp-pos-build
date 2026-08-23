<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Provision-at-v3 (first-tenant launch, Lane D1, spec §"Lane D1" task 2).
 *
 * Every DEVICE-capable `pos_terminals` row created from now on must carry
 * `fiscal_schema_version = 3` — both at the raw DB-default level (migration
 * `2026_07_31_000001_default_pos_terminals_fiscal_schema_version_3`) and
 * explicitly in TerminalController's two DEVICE creation paths (`store`,
 * `requestTerminal`), so the API contract is visible in code and does not
 * silently depend on the column default alone.
 *
 * `getOrCreateWebTerminal` is the ONE exception (round-2 fiscal-pos review
 * fix): web terminals are server-authoritative by construction — there is no
 * device to author SESSION_OPEN/SESSION_CLOSE locally — so a v3 web terminal
 * would trip ShiftController's device-authority guard
 * (`ShiftController.php:60-70,124-134`) and permanently dead-end the web
 * shifts dashboard. That path pins an EXPLICIT `fiscal_schema_version = 2`,
 * which is now LOAD-BEARING because the column default is 3.
 *
 * RED evidence (pre-fix, captured 2026-07-31 in this worktree before the
 * migration/controller edits landed): the raw DB default was 2 (migration
 * `2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals` — `default(2)`)
 * and none of the three `Terminal::create()` calls set the column, so every
 * assertion in this file failed with `1 !== 3` (raw default) / `2 !== 3`
 * (the two device creation paths) against the actual value on that revision.
 *
 * FACTORY DIVERGENCE (recorded, not fixed — out of this lane's scope):
 * `database/factories/TerminalFactory.php:31-32` pins `fiscal_schema_version
 * => 2` in its base `definition()` (a `v3Schema()` state exists for tests that
 * need v3) and is DELIBERATELY left unflipped by this lane. Separately,
 * Eloquent does NOT re-hydrate a model's in-memory attributes from DB column
 * DEFAULTs after `::create()` — a caller that omits the column from the
 * `create()` array (as the raw-DB-default test below does at the query-builder
 * level, bypassing Eloquent entirely) would, if done via Eloquent instead, get
 * back a model whose `fiscal_schema_version` attribute reads NULL in memory
 * (→ v2 semantics via code that does `?? 2`, e.g. ShiftController's guard)
 * even though the actual ROW the INSERT wrote is 3. This is why
 * TerminalController's device paths set the column EXPLICITLY rather than
 * relying on the default to flow back through the returned model.
 */
final class TerminalCreationFiscalSchemaVersionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
            // Owner ruling B-3, 2026-08-23: terminal acquisition now refuses a
            // location with POS switched off. The factory leaves `pos_enabled`
            // at the column's `default(false)`, so a fixture that intends to
            // host a till must say so. The refusal itself is covered by
            // TerminalLocationPosEnabledTest; this class is about the fiscal
            // schema version the creation paths stamp.
            'pos_enabled' => true,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        Permission::findOrCreate('pos.manage_shifts', 'sanctum');
        $this->user->givePermissionTo('pos.manage_terminals');
        $this->user->givePermissionTo('pos.operate_terminal');
        $this->user->givePermissionTo('pos.manage_shifts');

        Sanctum::actingAs($this->user);
    }

    /**
     * DB-level guarantee: a row inserted WITHOUT specifying the column at all
     * (bypassing every controller) still lands at schema 3 — the migration's
     * default flip, independent of any application code.
     */
    public function test_raw_db_default_is_schema_3(): void
    {
        $id = (string) Str::uuid();

        DB::table('pos_terminals')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => 'physical',
            'code' => 'RAW01',
            'name' => 'Raw Default Terminal',
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            // fiscal_schema_version deliberately omitted.
        ]);

        $version = DB::table('pos_terminals')->where('id', $id)->value('fiscal_schema_version');

        $this->assertSame(3, (int) $version, 'The pos_terminals.fiscal_schema_version column DEFAULT must be 3.');
    }

    /**
     * POST /api/v1/pos/terminals — TerminalController::store(), ~:111.
     */
    public function test_store_creates_terminal_at_schema_3(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Front Counter',
            'location_id' => $this->location->id,
        ]);

        $response->assertStatus(201);

        $terminal = Terminal::forCompany($this->company->id)->where('name', 'Front Counter')->firstOrFail();
        $this->assertSame(3, $terminal->fiscal_schema_version);
    }

    /**
     * POST /api/v1/pos/terminals/request — TerminalController::requestTerminal(), ~:387.
     */
    public function test_request_terminal_creates_terminal_at_schema_3(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-REQ-001',
            'suggested_name' => 'Requested Terminal',
        ]);

        $response->assertStatus(201);

        $terminal = Terminal::forCompany($this->company->id)
            ->where('hardware_identifier', 'HW-REQ-001')
            ->firstOrFail();
        $this->assertSame(3, $terminal->fiscal_schema_version);
    }

    /**
     * POST /api/v1/pos/terminals/web — TerminalController::getOrCreateWebTerminal(), ~:457.
     *
     * NOT v3 (round-2 fiscal-pos review fix): web terminals have no device to
     * author fiscal events locally, so this path pins an EXPLICIT
     * fiscal_schema_version = 2 that overrides the column DEFAULT of 3.
     */
    public function test_get_or_create_web_terminal_creates_terminal_at_schema_2(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ]);

        $response->assertStatus(201);

        $terminal = Terminal::forCompany($this->company->id)
            ->where('type', TerminalType::Web)
            ->firstOrFail();
        $this->assertSame(2, $terminal->fiscal_schema_version, 'Web terminals are server-authoritative and must stay at schema 2 despite the column default now being 3.');
    }

    /**
     * A second call to getOrCreateWebTerminal() for the SAME location returns
     * the existing (already schema-2) terminal rather than creating a second
     * one — guards against a false pass if the "create" branch were skipped.
     */
    public function test_get_or_create_web_terminal_is_idempotent_and_stays_at_schema_2(): void
    {
        $first = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ])->assertStatus(201)->json('data.id');

        $second = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ])->assertStatus(200)->json('data.id');

        $this->assertSame($first, $second);

        $terminal = Terminal::forCompany($this->company->id)->findOrFail($first);
        $this->assertSame(2, $terminal->fiscal_schema_version);
    }

    /**
     * The behavioural contract this lane creates, end to end: a terminal
     * provisioned through the REAL device creation path (store()) lands at
     * v3, and a legacy REST shift-open against that SAME terminal is refused
     * with the device-authority 409 — i.e. provision-at-v3 really does flip
     * the terminal into "the device authors shifts locally" mode, not just a
     * column value nobody reads. Mirrors ShiftOpenV3GuardTest but wires the
     * terminal through TerminalController::store() instead of the factory, so
     * the chain from THIS lane's creation-path fix to the pre-existing v3
     * shift guard (ShiftController.php:60-70) is pinned in one place.
     */
    public function test_a_v3_terminal_created_via_the_real_path_is_refused_legacy_shift_open(): void
    {
        $created = $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Front Counter',
            'location_id' => $this->location->id,
        ])->assertStatus(201)->json('data');

        $terminal = Terminal::forCompany($this->company->id)->findOrFail($created['id']);
        $this->assertSame(3, $terminal->fiscal_schema_version, 'Precondition: the real store() path must have created a v3 terminal.');

        // X-Client-Type: pos-tauri — the real device caller marker
        // (EnsureWebPosDemoTenant.php) so the request reaches the controller
        // on a non-demo tenant; the 409 under test is the controller's own
        // device-authority guard, not the demo-only gate.
        $response = $this->withHeader('X-Client-Type', 'pos-tauri')
            ->postJson('/api/v1/pos/shifts/open', [
                'terminal_code' => $terminal->code,
                'opening_cash' => '100.000',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SHIFT_DEVICE_AUTHORITY_REQUIRED');
    }
}
