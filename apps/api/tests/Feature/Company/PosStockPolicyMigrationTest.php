<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PosStockPolicyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_have_pos_stock_policy_defaulting_to_block(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        self::assertSame(PosStockPolicy::Block, $company->refresh()->pos_stock_policy);
    }

    public function test_pos_stock_policy_casts_to_enum(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_stock_policy' => PosStockPolicy::Warn,
        ]);

        self::assertSame(PosStockPolicy::Warn, $company->refresh()->pos_stock_policy);
    }

    /**
     * Step 10: new-company creation derives the default from the tenant vertical.
     * Mirrors what TenantProvisioningService / AuthController do at signup.
     *
     * NOTE: This test is intentionally a tautology — it calls defaultForVertical()
     * directly and would not fail if the production creation path were removed.
     * The load-bearing tests are test_register_restaurant_vertical_sets_pos_stock_policy_off
     * and test_register_retail_vertical_sets_pos_stock_policy_block below, which
     * exercise the real HTTP registration path.
     */
    public function test_restaurant_vertical_company_creation_derives_off(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Restaurant]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Café Test',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'pos_stock_policy' => PosStockPolicy::defaultForVertical($tenant->vertical),
        ]);

        self::assertSame(PosStockPolicy::Off, $company->refresh()->pos_stock_policy);
    }

    /**
     * Pins the REAL creation path: POST /api/v1/auth/register with restaurant
     * vertical must produce a company with pos_stock_policy = 'off'.
     *
     * This test would fail if the `'pos_stock_policy' => PosStockPolicy::defaultForVertical(...)`
     * line were removed from AuthController::register(), because the DB column
     * default is 'block', not 'off'.
     */
    public function test_register_restaurant_vertical_sets_pos_stock_policy_off(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Chef Owner',
            'email' => 'chef@bistrot.fr',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'Le Bistrot',
            'country_code' => 'FR',
            'vertical' => 'restaurant',
        ]);

        $response->assertCreated();

        $company = Company::where('name', 'Le Bistrot')->firstOrFail();
        self::assertSame(PosStockPolicy::Off, $company->pos_stock_policy);
    }

    /**
     * Pins the REAL creation path: POST /api/v1/auth/register with retail
     * vertical must produce a company with pos_stock_policy = 'block'.
     */
    public function test_register_retail_vertical_sets_pos_stock_policy_block(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Shop Owner',
            'email' => 'owner@boutique.fr',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'La Boutique',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $response->assertCreated();

        $company = Company::where('name', 'La Boutique')->firstOrFail();
        self::assertSame(PosStockPolicy::Block, $company->pos_stock_policy);
    }
}
