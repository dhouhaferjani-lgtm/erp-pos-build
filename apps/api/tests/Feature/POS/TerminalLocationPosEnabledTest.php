<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Owner ruling B-3 (2026-08-23) — `locations.pos_enabled` is enforced
 * SERVER-SIDE at terminal ACQUISITION.
 *
 * The column shipped in the original `create_locations_table` migration
 * (2025_11_30_105000, `pos_enabled` default false) and, until this lane, was
 * never read by any backend decision: the only production references were the
 * four writers and `LocationResource`. A location could therefore be marked
 * "POS disabled" in Settings and still hand out terminals — the flag was
 * decorative. This class pins the five acquisition paths that now honour it:
 *
 *  - `POST /api/v1/pos/terminals/claim`   (claim an existing physical terminal)
 *  - `POST /api/v1/pos/terminals/request` (device asks for a new terminal)
 *  - `POST /api/v1/pos/terminals`         (admin creates a terminal)
 *  - `POST /api/v1/pos/terminals/web`     (get-or-create the web terminal)
 *  - `GET  /api/v1/pos/terminals/available` (the device's terminal picker)
 *
 * NOT covered here, deliberately: shift-open enforcement. Refusing an open
 * shift at a disabled location requires a coordinated device build (terminal
 * payload + `terminalStore` + the ShiftController v2 mirror) and is carried by
 * a separate lane.
 */
final class TerminalLocationPosEnabledTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $enabledLocation;

    private Location $disabledLocation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->enabledLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Shop Floor',
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $this->disabledLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Back Warehouse',
            'type' => 'warehouse',
            'pos_enabled' => false,
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

        // Campaign lane N-12 — a terminal may only be acquired at a location
        // whose cash has somewhere of its own to go. This fixture keeps the
        // company on the pre-N-12 shape (an unattributed, GL-linked till that
        // still serves every location, the resolver's tier 2), so the claim
        // paths under test here stay exactly as hardening left them.
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'location_id' => null,
            'gl_account_id' => Account::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id])->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_claim_refuses_a_terminal_whose_location_has_pos_disabled(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->disabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-DISABLED-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');

        $this->assertNull(
            $terminal->fresh()?->hardware_identifier,
            'A refused claim must not bind the device to the terminal.',
        );
    }

    public function test_claim_still_succeeds_when_the_location_has_pos_enabled(): void
    {
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->enabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-ENABLED-1',
        ]);

        $response->assertStatus(200);
        $this->assertSame('HW-ENABLED-1', $terminal->fresh()?->hardware_identifier);
    }

    public function test_claim_reports_terminal_inactive_before_the_location_refusal(): void
    {
        // Ordering pin: the refusal sits AFTER the `is_active` check, so an
        // inactive terminal at a disabled location still reports the terminal
        // problem first — the operator fixes the nearer cause.
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->disabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => false,
            'hardware_identifier' => null,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-DISABLED-2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TERMINAL_INACTIVE');
    }

    /**
     * Gate r1 / P3-6 — the helper's FAILS CLOSED promise, made a contract.
     *
     * `locationHasPosEnabled()` re-applies `where('company_id', …)` rather than
     * trusting the location id it is handed. A terminal row whose `location_id`
     * points at ANOTHER company's location — a legacy or hand-edited row — must
     * therefore refuse, even though that foreign location is itself POS-enabled.
     * Without this case the guarantee lives only in a comment, and a future
     * "simplification" that drops the company predicate would pass every other
     * test in this class while silently letting a terminal be claimed against a
     * foreign company's switch.
     */
    public function test_claim_fails_closed_when_the_terminal_points_at_another_companys_location(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $foreignEnabledLocation = Location::factory()->create([
            'company_id' => $otherCompany->id,
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $foreignEnabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-FOREIGN-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');
        $this->assertNull($terminal->fresh()?->hardware_identifier);
    }

    public function test_request_terminal_refuses_a_pos_disabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->disabledLocation->id,
            'hardware_identifier' => 'HW-REQ-1',
            'suggested_name' => 'Warehouse Till',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');

        $this->assertSame(
            0,
            Terminal::query()->where('location_id', $this->disabledLocation->id)->count(),
            'A refused request must not create a terminal.',
        );
    }

    public function test_request_terminal_still_succeeds_at_a_pos_enabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->enabledLocation->id,
            'hardware_identifier' => 'HW-REQ-2',
            'suggested_name' => 'Front Till',
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            1,
            Terminal::query()->where('location_id', $this->enabledLocation->id)->count(),
        );
    }

    public function test_store_refuses_a_pos_disabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Warehouse Terminal',
            'location_id' => $this->disabledLocation->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');

        $this->assertSame(
            0,
            Terminal::query()->where('location_id', $this->disabledLocation->id)->count(),
        );
    }

    public function test_store_still_succeeds_at_a_pos_enabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals', [
            'name' => 'Front Terminal',
            'location_id' => $this->enabledLocation->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_web_terminal_refuses_a_pos_disabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->disabledLocation->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');

        $this->assertSame(
            0,
            Terminal::query()->where('location_id', $this->disabledLocation->id)->count(),
        );
    }

    public function test_web_terminal_refuses_even_when_one_already_exists_at_the_disabled_location(): void
    {
        // Fail closed: a location whose POS was switched off must stop handing
        // out its already-provisioned web terminal too, otherwise the switch
        // only stops NEW devices and the running one keeps selling.
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->disabledLocation->id,
            'type' => TerminalType::Web,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->disabledLocation->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'LOCATION_POS_DISABLED');
    }

    public function test_web_terminal_still_resolves_at_a_pos_enabled_location(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/web', [
            'location_id' => $this->enabledLocation->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            1,
            Terminal::query()->where('location_id', $this->enabledLocation->id)->count(),
        );
    }

    public function test_available_excludes_terminals_at_pos_disabled_locations(): void
    {
        $offered = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->enabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $hidden = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->disabledLocation->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ]);

        $response = $this->getJson('/api/v1/pos/terminals/available');

        $response->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($offered->id, $ids);
        $this->assertNotContains($hidden->id, $ids, 'The picker must never offer a terminal at a POS-disabled location.');
    }
}
