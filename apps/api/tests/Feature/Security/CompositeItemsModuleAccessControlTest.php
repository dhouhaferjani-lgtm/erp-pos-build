<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test for the CompositeItems module gating and its split from Inventory.
 *
 * Composite items (dishes/combos/bundles) and menu modifiers are gated by the
 * CompositeItems module (a default for restaurant/coffee_shop) and do NOT require
 * inventory. Ingredient recipes drive stock depletion/costing and remain gated by
 * the Inventory module — so an F&B tenant must upgrade into Inventory to define
 * recipes, but can build dishes + modifiers without it.
 */
final class CompositeItemsModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $extras
     * @return array{user: User, company: Company}
     */
    private function makeContext(Vertical $vertical, string $slug, array $extras = []): array
    {
        $tenant = Tenant::create([
            'name' => "Test {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
            'enabled_extras' => $extras,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$slug} Company",
            'legal_name' => "Test {$slug} Company LLC",
            'tax_id' => 'TAX'.strtoupper($slug),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => "user@{$slug}.test",
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return ['user' => $user, 'company' => $company];
    }

    /** @test */
    public function a_vertical_without_composite_items_is_blocked_with_403(): void
    {
        $ctx = $this->makeContext(Vertical::Retail, 'retail-ci', []);

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/composite-items');

        $response->assertStatus(403);
    }

    /** @test */
    public function an_fnb_vertical_can_reach_composite_items_without_inventory(): void
    {
        // Restaurant has CompositeItems as a default module but NOT Inventory.
        $ctx = $this->makeContext(Vertical::Restaurant, 'restaurant-ci', []);

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/composite-items');

        $response->assertSuccessful();
    }

    /** @test */
    public function modifiers_are_gated_by_composite_items_not_inventory(): void
    {
        // Restaurant (CompositeItems, no Inventory) can manage menu modifiers.
        $ctx = $this->makeContext(Vertical::Restaurant, 'restaurant-mod', []);

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/modifier-groups');

        $response->assertSuccessful();
    }

    /** @test */
    public function recipes_and_costing_do_not_require_the_inventory_module(): void
    {
        // Recipe definition + theoretical costing live under CompositeItems. A
        // restaurant (CompositeItems, no Inventory) can define/cost recipes with a
        // manual cost_price and grow into Inventory later for depletion + WAC.
        $ctx = $this->makeContext(Vertical::Restaurant, 'restaurant-rec', []);

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/composite-items/00000000-0000-0000-0000-000000000000/recipes');

        // Past the CompositeItems gate: a 404 from the controller, not a 403 block.
        $response->assertStatus(404);
    }

    /** @test */
    public function recipes_are_blocked_without_the_composite_items_module(): void
    {
        // Retail without the CompositeItems extra cannot reach recipe routes.
        $ctx = $this->makeContext(Vertical::Retail, 'retail-rec', []);

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/composite-items/00000000-0000-0000-0000-000000000000/recipes');

        $response->assertStatus(403);
    }
}
