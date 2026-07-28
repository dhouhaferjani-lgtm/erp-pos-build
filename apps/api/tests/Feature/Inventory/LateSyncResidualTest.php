<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LateSyncResidualDetector;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LateSyncResidualTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Late Sync Tenant',
            'slug' => 'late-sync-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Late Sync Company',
            'legal_name' => 'Late Sync Company LLC',
            'tax_id' => 'LSR-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Late Sync Reviewer',
            'email' => 'late-sync-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'LSR-01',
            'name' => 'Late Sync Shop',
            'type' => LocationType::Shop,
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LSR-001',
            'name' => 'Late Sync Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);
    }

    /**
     * @return iterable<string, array{occurredAt: string, createdAt: string, detected: bool}>
     */
    public static function movementTimingCases(): iterable
    {
        yield 'sale before count boundary, row after finalize' => [
            'occurredAt' => '2026-07-28 09:30:00',
            'createdAt' => '2026-07-28 11:30:00',
            'detected' => true,
        ];

        yield 'sale after count boundary, row before finalize' => [
            'occurredAt' => '2026-07-28 10:30:00',
            'createdAt' => '2026-07-28 10:45:00',
            'detected' => false,
        ];

        yield 'sale before count boundary, row before finalize' => [
            'occurredAt' => '2026-07-28 09:30:00',
            'createdAt' => '2026-07-28 10:45:00',
            'detected' => false,
        ];
    }

    #[DataProvider('movementTimingCases')]
    public function test_detects_only_sales_that_arrived_after_finalize(
        string $occurredAt,
        string $createdAt,
        bool $detected,
    ): void {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $movement = $this->saleMovement($occurredAt, $createdAt, '2.0000');

        $residuals = app(LateSyncResidualDetector::class)->forCounting($counting);

        $this->assertCount($detected ? 1 : 0, $residuals);
        if ($detected) {
            $this->assertSame($movement->id, $residuals[0]['movement_id']);
            $this->assertSame($counting->id, $residuals[0]['counting_id']);
            $this->assertSame('2.0000', $residuals[0]['quantity']);
            $this->assertSame(4, $residuals[0]['product']['quantity_decimals']);
        }
    }

    public function test_multiple_counts_attribute_a_late_sale_to_the_latest_count_it_slipped_past(): void
    {
        $earlier = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $later = $this->finalizedCounting('2026-07-28 13:00:00', '2026-07-28 14:00:00');
        $movement = $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 15:00:00', '1.5000');

        $this->assertSame([], app(LateSyncResidualDetector::class)->forCounting($earlier));

        $laterResiduals = app(LateSyncResidualDetector::class)->forCounting($later);
        $this->assertCount(1, $laterResiduals);
        $this->assertSame($movement->id, $laterResiduals[0]['movement_id']);
        $this->assertSame($later->id, $laterResiduals[0]['counting_id']);
        $this->assertSame('1.5000', $laterResiduals[0]['quantity']);
    }

    public function test_sale_arriving_after_finalize_but_before_successful_apply_is_not_a_residual(): void
    {
        $counting = $this->finalizedCounting(
            '2026-07-28 10:00:00',
            '2026-07-28 11:00:00',
            '2026-07-28 11:15:00',
        );
        $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 11:05:00', '2.0000');

        $this->assertSame([], app(LateSyncResidualDetector::class)->forCounting($counting));
    }

    public function test_sale_at_the_exact_count_boundary_after_apply_is_detected(): void
    {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $movement = $this->saleMovement('2026-07-28 10:00:00', '2026-07-28 11:30:00', '2.0000');

        $residuals = app(LateSyncResidualDetector::class)->forCounting($counting);

        $this->assertCount(1, $residuals);
        $this->assertSame($movement->id, $residuals[0]['movement_id']);
    }

    public function test_flagged_unposted_count_item_does_not_report_a_residual(): void
    {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $counting->items()->update([
            'is_flagged' => true,
            'flag_reasons' => ['negative_at_apply'],
        ]);
        $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 11:30:00', '2.0000');

        $this->assertSame([], app(LateSyncResidualDetector::class)->forCounting($counting));
    }

    public function test_posted_variance_item_remains_eligible_even_when_flagged_for_review(): void
    {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $counting->items()->update([
            'is_flagged' => true,
            'flag_reasons' => ['normalized_agreement'],
        ]);
        $movement = $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 11:30:00', '2.0000');

        $residuals = app(LateSyncResidualDetector::class)->forCounting($counting);

        $this->assertCount(1, $residuals);
        $this->assertSame($movement->id, $residuals[0]['movement_id']);
    }

    public function test_posted_clock_skew_item_remains_eligible(): void
    {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $counting->items()->update([
            'is_flagged' => true,
            'flag_reasons' => ['clock_skew'],
        ]);
        $movement = $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 11:30:00', '2.0000');

        $residuals = app(LateSyncResidualDetector::class)->forCounting($counting);

        $this->assertCount(1, $residuals);
        $this->assertSame($movement->id, $residuals[0]['movement_id']);
    }

    public function test_newer_count_that_absorbed_a_sale_prevents_fallthrough_to_an_older_count(): void
    {
        $earlier = $this->finalizedCounting(
            '2026-07-28 10:00:00',
            '2026-07-28 11:00:00',
            '2026-07-28 11:05:00',
        );
        $later = $this->finalizedCounting(
            '2026-07-28 13:00:00',
            '2026-07-28 14:00:00',
            '2026-07-28 14:05:00',
        );
        $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 13:30:00', '1.5000');

        $this->assertSame([], app(LateSyncResidualDetector::class)->forCounting($earlier));
        $this->assertSame([], app(LateSyncResidualDetector::class)->forCounting($later));
    }

    public function test_detection_is_read_only_for_stock_and_movement_rows(): void
    {
        $counting = $this->finalizedCounting('2026-07-28 10:00:00', '2026-07-28 11:00:00');
        $this->saleMovement('2026-07-28 09:30:00', '2026-07-28 11:30:00', '2.0000');
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => '8.0000',
            'reserved' => '0.0000',
        ]);
        $stockBefore = DB::table('stock_levels')->orderBy('id')->get()->toJson();
        $movementsBefore = DB::table('stock_movements')->orderBy('id')->get()->toJson();

        $residuals = app(LateSyncResidualDetector::class)->forCounting($counting);

        $this->assertCount(1, $residuals);
        $this->assertSame($stockBefore, DB::table('stock_levels')->orderBy('id')->get()->toJson());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->orderBy('id')->get()->toJson());
    }

    private function finalizedCounting(
        string $countedAt,
        string $finalizedAt,
        ?string $appliedAt = null,
    ): InventoryCounting {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'execution_mode' => CountingExecutionMode::Parallel,
            'status' => CountingStatus::Finalized,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'finalized_at' => CarbonImmutable::parse($finalizedAt),
        ]);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.0000',
            'count_1_qty' => '8.0000',
            'final_qty' => '8.0000',
            'final_qty_as_of' => CarbonImmutable::parse($countedAt),
            'expected_qty_at_apply' => '8.0000',
            'replay_audit' => [
                'windowFrom' => CarbonImmutable::parse($countedAt)->toIso8601String(),
                'windowTo' => CarbonImmutable::parse($appliedAt ?? $finalizedAt)->toIso8601String(),
                'replayedDelta' => '0.0000',
                'onHandAtApply' => '8.0000',
                'expectedAtApply' => '8.0000',
            ],
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
            'is_flagged' => false,
            'is_unexpected_item' => false,
        ]);

        return $counting;
    }

    private function saleMovement(string $occurredAt, string $createdAt, string $quantity): StockMovement
    {
        if (! is_numeric($quantity)) {
            throw new \InvalidArgumentException('Test movement quantity must be numeric.');
        }

        $movement = new StockMovement([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => $quantity,
            'quantity_before' => '10.0000',
            'quantity_after' => bcsub('10.0000', $quantity, 4),
            'occurred_at' => CarbonImmutable::parse($occurredAt),
        ]);
        $movement->created_at = Carbon::parse($createdAt);
        $movement->updated_at = Carbon::parse($createdAt);
        $movement->save();

        return $movement;
    }
}
