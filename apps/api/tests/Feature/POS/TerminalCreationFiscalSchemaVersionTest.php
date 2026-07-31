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
 * Every `pos_terminals` row created from now on must carry
 * `fiscal_schema_version = 3` — both at the raw DB-default level (migration
 * `2026_07_31_000001_default_pos_terminals_fiscal_schema_version_3`) and
 * explicitly in each of TerminalController's three creation paths (`store`,
 * `requestTerminal`, `getOrCreateWebTerminal`), so the API contract is visible
 * in code and does not silently depend on the column default alone.
 *
 * RED evidence (pre-fix, captured 2026-07-31 in this worktree before the
 * migration/controller edits landed): the raw DB default was 2 (migration
 * `2026_05_01_000002_add_fiscal_schema_version_to_pos_terminals` — `default(2)`)
 * and none of the three `Terminal::create()` calls set the column, so every
 * assertion in this file failed with `1 !== 3` (raw default) / `2 !== 3`
 * (the three creation paths) against the actual value on that revision.
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
        $this->user->givePermissionTo('pos.manage_terminals');
        $this->user->givePermissionTo('pos.operate_terminal');

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
     * POST /api/v1/pos/terminals/web — TerminalController::getOrCreateWebTerminal(), ~:450.
     */
    public function test_get_or_create_web_terminal_creates_terminal_at_schema_3(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ]);

        $response->assertStatus(201);

        $terminal = Terminal::forCompany($this->company->id)
            ->where('type', TerminalType::Web)
            ->firstOrFail();
        $this->assertSame(3, $terminal->fiscal_schema_version);
    }

    /**
     * A second call to getOrCreateWebTerminal() for the SAME location returns
     * the existing (already schema-3) terminal rather than creating a second
     * one — guards against a false pass if the "create" branch were skipped.
     */
    public function test_get_or_create_web_terminal_is_idempotent_and_stays_at_schema_3(): void
    {
        $first = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ])->assertStatus(201)->json('data.id');

        $second = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->location->id,
        ])->assertStatus(200)->json('data.id');

        $this->assertSame($first, $second);

        $terminal = Terminal::forCompany($this->company->id)->findOrFail($first);
        $this->assertSame(3, $terminal->fiscal_schema_version);
    }
}
