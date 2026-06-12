<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BackfillPosStockPolicyCommand tests.
 *
 * NOTE: The test environment runs shared-DB (tenancy_resolver.db_per_tenant=false),
 * so tenancy()->initialize() is skipped inside the command. All companies live in
 * the current DB context. We test the per-company decision logic + --dry-run flag.
 */
final class BackfillPosStockPolicyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure we are in shared-DB mode (the test default).
        config(['tenancy_resolver.db_per_tenant' => false]);
    }

    public function test_restaurant_vertical_tenant_companies_are_set_to_off(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Restaurant]);
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_stock_policy' => PosStockPolicy::Block,
        ]);

        $this->artisan('pos:stock-policy-backfill')
            ->assertSuccessful();

        self::assertSame(PosStockPolicy::Off, $company->refresh()->pos_stock_policy);
    }

    public function test_retail_vertical_tenant_companies_stay_block(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_stock_policy' => PosStockPolicy::Block,
        ]);

        $this->artisan('pos:stock-policy-backfill')
            ->assertSuccessful();

        self::assertSame(PosStockPolicy::Block, $company->refresh()->pos_stock_policy);
    }

    public function test_dry_run_does_not_write(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Restaurant]);
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_stock_policy' => PosStockPolicy::Block,
        ]);

        $this->artisan('pos:stock-policy-backfill', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY-RUN]');

        // Value must remain unchanged
        self::assertSame(PosStockPolicy::Block, $company->refresh()->pos_stock_policy);
    }
}
