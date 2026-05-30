<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Drift-exposing coverage for {@see WeightedAverageCostService}.
 *
 * WAC blends running stock value with each receipt's value. The pre-bcmath
 * implementation computed `$currentValue + ($quantity * $landedUnitCost)` and
 * `$newValue / $newQty` in native float, then `round(..., scale)`. Two distinct
 * defects are exercised here:
 *
 *  1. A long run of small (qty=0.1, cost=0.1 TND) receipts must keep the running
 *     WAC pinned at exactly 0.100 — any sub-millième float creep is drift.
 *
 *  2. A short sequence whose exact WAC is a non-terminating fraction
 *     (0.51 / 1.1 = 0.463636…). At TND scale 3 the canonical bcmath boundary
 *     value is 0.463 (bcmath truncates at the boundary, matching the convention
 *     already used by the migrated Promotion/Recipe/Accounting services). The
 *     old float path rounded half-up to 0.464, so this assertion is red on the
 *     pre-bcmath implementation and green afterwards.
 */
class WacBcmathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'WAC Bcmath Tenant',
            'slug' => 'wac-bcmath-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WAC Bcmath Company',
            'legal_name' => 'WAC Bcmath Company LLC',
            'tax_id' => 'TAX-WAC-001',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        // Bind TND company → scale 3 for the real CurrencyScaleResolver
        // resolved into the service under test.
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'code' => 'WH-WAC-01',
            'name' => 'WAC Warehouse',
            'type' => LocationType::Warehouse,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '0',
            'sale_price' => '0',
        ]);
    }

    public function test_running_wac_does_not_drift_over_100_small_receipts(): void
    {
        /** @var WeightedAverageCostService $service */
        $service = app(WeightedAverageCostService::class);

        // 100 sequential receipts of qty=0.1 at a constant 0.100 TND unit cost.
        // The exact WAC after every receipt is 0.100 — any deviation is drift.
        for ($i = 0; $i < 100; $i++) {
            $service->recordPurchase(
                product: $this->product,
                location: $this->location,
                quantity: 0.1,
                landedUnitCost: 0.1,
                reference: 'PO-WAC-'.$i,
            );

            $this->product->refresh();

            $this->assertSame(
                '0.100',
                (string) $this->product->cost_price,
                "WAC drifted at receipt #{$i}: {$this->product->cost_price}",
            );
        }

        // Final stock quantity must be exactly 10.0000 (100 * 0.1).
        $stockLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(0, bccomp('10.0000', (string) $stockLevel->quantity, 4));

        // And the WAC is still exactly 0.100 — no millième of drift.
        $this->assertSame('0.100', (string) $this->product->cost_price);
    }

    public function test_running_wac_uses_exact_bcmath_boundary_on_non_terminating_fraction(): void
    {
        /** @var WeightedAverageCostService $service */
        $service = app(WeightedAverageCostService::class);

        // r1: 0.3 @ 0.700 → value 0.21, qty 0.3 → WAC 0.700
        $service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 0.3,
            landedUnitCost: 0.7,
            reference: 'PO-FRAC-1',
        );
        $this->product->refresh();
        $this->assertSame('0.700', (string) $this->product->cost_price);

        // r2: 0.7 @ 0.300 → value 0.21 + 0.21 = 0.42, qty 1.0 → WAC 0.420
        $service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 0.7,
            landedUnitCost: 0.3,
            reference: 'PO-FRAC-2',
        );
        $this->product->refresh();
        $this->assertSame('0.420', (string) $this->product->cost_price);

        // r3: 0.1 @ 0.900 → value 0.42 + 0.09 = 0.51, qty 1.1
        //     exact WAC = 0.51 / 1.1 = 0.463636…  → bcmath boundary at scale 3 = 0.463
        //     (old float `round()` produced 0.464 — drift away from the exact value).
        $service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 0.1,
            landedUnitCost: 0.9,
            reference: 'PO-FRAC-3',
        );
        $this->product->refresh();
        $this->assertSame('0.463', (string) $this->product->cost_price);
    }

    public function test_calculate_new_wac_blends_without_float_drift(): void
    {
        /** @var WeightedAverageCostService $service */
        $service = app(WeightedAverageCostService::class);

        // Blend 3 units @ 0.1 with 7 units @ 0.1 → exact WAC = 0.100.
        $wac = $service->calculateNewWAC(
            currentQty: 3.0,
            currentCost: 0.1,
            newQty: 7.0,
            newCost: 0.1,
        );

        $this->assertSame(0, bccomp('0.100', (string) $wac, 3));

        // Non-terminating blend: (0.21 + 0.09) / 1.1 = 0.30 / 1.1 = 0.272727…
        // bcmath boundary at scale 3 = 0.272 (old float rounded half-up to 0.273).
        $wacFraction = $service->calculateNewWAC(
            currentQty: 1.0,
            currentCost: 0.21,
            newQty: 0.1,
            newCost: 0.9,
        );

        $this->assertSame(0, bccomp('0.272', (string) $wacFraction, 3));
    }
}
