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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Session B lane Q-13 — the `module:Tables` sibling of the Q-9 kitchen/order hole.
 *
 * Rule-12 both-layer module gating. The web layer has hidden the whole table
 * surface behind `ModuleGuard module="Tables"` (`apps/web/src/routes/index.tsx:2991`)
 * and `module: 'Tables'` on the sidebar entry
 * (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:237`), but the backend
 * group at `app/Modules/POS/routes_tables.php` mirrored nothing — every floor and
 * table route was reachable by any holder of `pos.manage_tables` on ANY vertical,
 * retail and parapharmacy included (tenancy gate r1 on Q-9, Finding 2).
 *
 * `Tables` is the correct key (`config/verticals.php` is the SoT):
 *   - `restaurant`  → Tables is a DEFAULT module.
 *   - `coffee_shop` → Tables is only a COMPATIBLE EXTRA; it must 403 without the
 *                     extra and pass with `enabled_extras: ['Tables']`.
 *   - `retail` / `parapharmacy` → neither default nor compatible ⇒ always 403.
 *
 * Placed in the LIVE `security-regression` whole-directory lane (no manifest
 * ceiling to raise), same as `PosOrderKitchenModuleAccessControlTest`.
 */
final class PosTablesModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an authenticated tenant context for the given vertical.
     *
     * @param  list<string>  $enabledExtras
     * @return array{user: User, company: Company, tenant: Tenant}
     */
    private function makeContext(Vertical $vertical, string $slug, array $enabledExtras = []): array
    {
        $tenant = Tenant::create([
            'name' => "Test {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
            'enabled_extras' => $enabledExtras,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$slug} Company",
            'legal_name' => "Test {$slug} Company LLC",
            'tax_id' => 'TAX'.strtoupper(str_replace('-', '', $slug)),
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

        return ['user' => $user, 'company' => $company, 'tenant' => $tenant];
    }

    /**
     * Every LIVE route declared in `routes_tables.php`, asserted 403.
     *
     * @param  array{user: User, company: Company, tenant: Tenant}  $ctx
     */
    private function assertEveryTablesRouteForbidden(array $ctx): void
    {
        $floorId = Str::uuid()->toString();
        $tableId = Str::uuid()->toString();
        $user = $ctx['user'];

        // Floor CRUD
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/pos/floors')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/pos/floors', ['name' => 'Main', 'position' => 1])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/pos/floors/{$floorId}", ['name' => 'Renamed'])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/pos/floors/{$floorId}")->assertStatus(403);

        // Table CRUD
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/pos/tables')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/pos/tables', ['floor_id' => $floorId, 'number' => 'T1'])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/pos/tables/{$tableId}", ['number' => 'T2'])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/pos/tables/{$tableId}")->assertStatus(403);

        // Table operations
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/pos/tables/{$tableId}/release")->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/pos/tables/{$tableId}/status", ['status' => 'available'])->assertStatus(403);
    }

    public function test_a_retail_tenant_is_blocked_from_every_tables_route_with_403(): void
    {
        $this->assertEveryTablesRouteForbidden(
            $this->makeContext(Vertical::Retail, 'retail-tables'),
        );
    }

    public function test_a_parapharmacy_tenant_is_blocked_from_every_tables_route_with_403(): void
    {
        $this->assertEveryTablesRouteForbidden(
            $this->makeContext(Vertical::Parapharmacy, 'parapharmacy-tables'),
        );
    }

    /**
     * `restaurant` carries Tables as a DEFAULT module — the surface must stay open.
     */
    public function test_a_restaurant_tenant_can_still_reach_the_tables_routes(): void
    {
        $ctx = $this->makeContext(Vertical::Restaurant, 'restaurant-tables');

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/floors')
            ->assertSuccessful();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/tables')
            ->assertSuccessful();
    }

    /**
     * `coffee_shop` has Menu but NOT Tables by default — the compatible-extra path.
     */
    public function test_a_coffee_shop_tenant_without_the_tables_extra_is_blocked(): void
    {
        $this->assertEveryTablesRouteForbidden(
            $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-no-tables'),
        );
    }

    public function test_a_coffee_shop_tenant_with_the_tables_extra_can_reach_the_tables_routes(): void
    {
        $ctx = $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-with-tables', ['Tables']);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/floors')
            ->assertSuccessful();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/tables')
            ->assertSuccessful();
    }
}
