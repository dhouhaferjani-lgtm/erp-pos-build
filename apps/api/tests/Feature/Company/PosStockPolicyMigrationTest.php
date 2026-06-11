<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Tenant\Domain\Tenant;
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
}
