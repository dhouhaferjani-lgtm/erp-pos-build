<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Application\Services\LocationStockPolicyResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task A4: per-location stock policy + onboarding fields.
 *
 * Resolution matrix (LocationStockPolicyResolver::resolve()):
 *   onboarding_mode ? Off : (pos_stock_policy_override ?? company.pos_stock_policy)
 */
final class LocationStockPolicyResolverTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'pos_stock_policy' => PosStockPolicy::Warn,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    private function makeLocation(array $attributes = []): Location
    {
        return Location::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Branch',
            'type' => LocationType::Shop,
        ], $attributes));
    }

    // --- Resolution matrix ---------------------------------------------

    public function test_resolves_company_policy_when_no_override_and_not_onboarding(): void
    {
        $location = $this->makeLocation();

        $policy = (new LocationStockPolicyResolver)->resolve($location->load('company'));

        self::assertSame(PosStockPolicy::Warn, $policy);
    }

    public function test_override_takes_precedence_over_company_policy(): void
    {
        $location = $this->makeLocation([
            'pos_stock_policy_override' => PosStockPolicy::Block->value,
        ]);

        $policy = (new LocationStockPolicyResolver)->resolve($location->load('company'));

        self::assertSame(PosStockPolicy::Block, $policy);
    }

    public function test_onboarding_mode_forces_off_with_no_override(): void
    {
        $location = $this->makeLocation(['onboarding_mode' => true]);

        $policy = (new LocationStockPolicyResolver)->resolve($location->load('company'));

        self::assertSame(PosStockPolicy::Off, $policy);
    }

    public function test_onboarding_mode_forces_off_even_with_an_override_set(): void
    {
        $location = $this->makeLocation([
            'onboarding_mode' => true,
            'pos_stock_policy_override' => PosStockPolicy::Block->value,
        ]);

        $policy = (new LocationStockPolicyResolver)->resolve($location->load('company'));

        self::assertSame(PosStockPolicy::Off, $policy);
    }

    public function test_location_has_onboarding_and_override_default_values(): void
    {
        $location = $this->makeLocation();
        $location->refresh();

        self::assertFalse($location->onboarding_mode);
        self::assertNull($location->pos_stock_policy_override);
    }

    // --- TerminalResource reflects the resolved value -------------------

    public function test_terminal_resource_exposes_resolved_policy_for_onboarding_location(): void
    {
        $location = $this->makeLocation(['onboarding_mode' => true]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertSame('off', $payload['pos_stock_policy']);
    }

    public function test_terminal_resource_exposes_resolved_policy_for_override(): void
    {
        $location = $this->makeLocation([
            'pos_stock_policy_override' => PosStockPolicy::Block->value,
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        // Company policy is Warn but the location override forces Block.
        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertSame('block', $payload['pos_stock_policy']);
    }

    public function test_terminal_resource_falls_back_to_company_policy_when_no_override(): void
    {
        $location = $this->makeLocation();
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertSame('warn', $payload['pos_stock_policy']);
    }

    // --- Settings controller/request roundtrip --------------------------

    public function test_create_location_accepts_onboarding_mode_and_override(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Onboarding Branch',
                'type' => 'shop',
                'onboarding_mode' => true,
                'pos_stock_policy_override' => 'block',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.onboarding_mode', true);
        $response->assertJsonPath('data.pos_stock_policy_override', 'block');

        $this->assertDatabaseHas('locations', [
            'name' => 'Onboarding Branch',
            'onboarding_mode' => true,
            'pos_stock_policy_override' => 'block',
        ]);
    }

    public function test_update_location_accepts_onboarding_mode_and_override_roundtrip(): void
    {
        $location = $this->makeLocation();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'onboarding_mode' => true,
                'pos_stock_policy_override' => 'warn',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.onboarding_mode', true);
        $response->assertJsonPath('data.pos_stock_policy_override', 'warn');

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'onboarding_mode' => true,
            'pos_stock_policy_override' => 'warn',
        ]);
    }

    public function test_update_location_rejects_invalid_pos_stock_policy_override(): void
    {
        $location = $this->makeLocation();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'pos_stock_policy_override' => 'not-a-policy',
            ]);

        $this->assertApiValidationErrors($response, ['pos_stock_policy_override']);
    }

    public function test_update_location_can_clear_override_to_inherit_company_policy(): void
    {
        $location = $this->makeLocation([
            'pos_stock_policy_override' => PosStockPolicy::Block->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/locations/{$location->id}", [
                'pos_stock_policy_override' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.pos_stock_policy_override', null);

        $policy = (new LocationStockPolicyResolver)->resolve($location->fresh()->load('company'));
        self::assertSame(PosStockPolicy::Warn, $policy);
    }
}
