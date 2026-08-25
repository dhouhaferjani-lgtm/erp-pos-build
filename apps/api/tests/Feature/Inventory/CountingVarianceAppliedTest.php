<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Services\CountingDiscrepancyReportService;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campaign W4-6 — a count must APPLY its variance.
 *
 * The defect this class pins: `basket_window` (any stock movement within
 * ±`ambiguity_window_minutes` of the count instant) blocked the whole
 * adjustment, so a real shrinkage/gain was flagged and silently discarded while
 * the summary reported `items_with_variance: 0`.
 *
 * The contract after the fix:
 *  - a movement near the count instant is ANNOTATED, never a reason to skip the
 *    stock write — the replay (`expected_now = counted + Σ(asOf, now]`) is what
 *    keeps an in-count sale counted exactly once;
 *  - finalize writes ONE count-correction movement per VARYING line and none at
 *    all for a line that agrees (document-per-action: a zero movement justifies
 *    nothing);
 *  - a batch-tracked line moves its LOT ledger in the same direction, and never
 *    re-mints a phantom DEFAULT lot on top of real lots (W2-7);
 *  - the count report carries expected / counted / variance / applied per line
 *    and a summary that agrees with them.
 */
final class CountingVarianceAppliedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'W46 Tenant',
            'slug' => 'w46-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W46 Company',
            'legal_name' => 'W46 Company LLC',
            'tax_id' => 'W46-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W46 User',
            'email' => 'w46-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'W46-'.uniqid(),
            'name' => 'W46 Main Location',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);
    }

    // ---------------------------------------------------------------- fixtures

    /** @param  numeric-string  $costPrice */
    private function product(string $sku, string $costPrice = '8.500000', bool $batchTracked = false): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku.'-'.uniqid(),
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => $costPrice,
            'requires_batch_tracking' => $batchTracked,
            'default_shelf_life_days' => $batchTracked ? 365 : null,
        ]);
    }

    /** @param  numeric-string  $quantity */
    private function setOnHand(Product $product, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    /**
     * @param  numeric-string  $before
     * @param  numeric-string  $after
     */
    private function movement(Product $product, string $before, string $after, CarbonImmutable $occurredAt): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Receipt,
            'quantity' => bcsub($after, $before, 4),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function counting(int $windowMinutes = 15): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CNT-W46-'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => $windowMinutes,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    /**
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $theoretical
     */
    private function item(
        InventoryCounting $counting,
        Product $product,
        string $finalQty,
        CarbonImmutable $asOf,
        string $theoretical,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => $theoretical,
            'count_1_qty' => $finalQty,
            'count_2_qty' => $finalQty,
            'final_qty' => $finalQty,
            'final_qty_as_of' => $asOf,
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);
    }

    private function fire(InventoryCounting $counting): void
    {
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: $counting->items()->count(),
            totalVariance: '0.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    private function correctionsFor(Product $product): int
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->count();
    }

    private function onHand(Product $product): string
    {
        return (string) StockLevel::query()->where('product_id', $product->id)->value('quantity');
    }

    // ------------------------------------------------------------------- cases

    /**
     * W4-6 headline: short 2 / over 1 / exact, every line carrying a goods
     * receipt 13 minutes before the count instant (the campaign's exact shape).
     * TWO adjustments must post; the agreeing line must post nothing.
     */
    public function test_short_over_and_exact_produce_one_adjustment_per_varying_line(): void
    {
        $t = CarbonImmutable::now()->subHours(2);

        $short = $this->product('COMP-MAGN');
        $over = $this->product('HUIL-ARGA');
        $exact = $this->product('CREM-SOLA');

        $this->setOnHand($short, '70.0000');
        $this->setOnHand($over, '40.0000');
        $this->setOnHand($exact, '25.0000');

        // A receipt 13 min BEFORE the count instant — inside ±15, which is what
        // used to suppress every one of these lines.
        $this->movement($short, '50.0000', '70.0000', $t->subMinutes(13));
        $this->movement($over, '20.0000', '40.0000', $t->subMinutes(13));

        $counting = $this->counting(15);
        $shortItem = $this->item($counting, $short, '68.0000', $t, '70.0000');
        $overItem = $this->item($counting, $over, '41.0000', $t, '40.0000');
        $exactItem = $this->item($counting, $exact, '25.0000', $t, '25.0000');

        $this->fire($counting);

        // Stock reflects the count.
        self::assertSame('68.0000', $this->onHand($short));
        self::assertSame('41.0000', $this->onHand($over));
        self::assertSame('25.0000', $this->onHand($exact));

        // One movement per VARYING line, none for the agreeing one.
        self::assertSame(1, $this->correctionsFor($short));
        self::assertSame(1, $this->correctionsFor($over));
        self::assertSame(0, $this->correctionsFor($exact));

        self::assertDatabaseHas('stock_movements', [
            'product_id' => $short->id,
            'movement_type' => MovementType::Adjustment->value,
            'reason' => MovementReason::CountCorrection->value,
            'quantity' => '-2.0000',
            'quantity_before' => '70.0000',
            'quantity_after' => '68.0000',
            'reference_type' => StockMovementReferenceType::InventoryCounting->value,
            'reference_id' => $counting->id,
        ]);
        self::assertDatabaseHas('stock_movements', [
            'product_id' => $over->id,
            'quantity' => '1.0000',
            'quantity_before' => '40.0000',
            'quantity_after' => '41.0000',
            'reference_id' => $counting->id,
        ]);

        // basket_window is an annotation now, not a veto: the reason is still
        // recorded for the reviewer, and the correction posted anyway.
        $shortItem->refresh();
        self::assertContains(CountingItemFlagReason::BasketWindow->value, $shortItem->flag_reasons ?? []);
        self::assertNotNull($shortItem->replay_audit);

        $overItem->refresh();
        self::assertContains(CountingItemFlagReason::BasketWindow->value, $overItem->flag_reasons ?? []);

        $exactItem->refresh();
        self::assertNotNull($exactItem->replay_audit);
    }

    /**
     * The half the campaign verified as CORRECT and which must not regress: a
     * sale inside the ambiguity window is replayed onto the counted quantity, so
     * it is deducted exactly once (56, never 52 and never 60).
     */
    public function test_an_in_count_sale_is_counted_exactly_once(): void
    {
        $t = CarbonImmutable::now()->subHours(2);
        $siro = $this->product('SIRO-TOUX');

        // Counted 60 at T; a 4-unit sale lands 5 minutes later (inside ±15).
        $this->setOnHand($siro, '56.0000');
        $this->movement($siro, '60.0000', '56.0000', $t->addMinutes(5));

        $counting = $this->counting(15);
        $item = $this->item($counting, $siro, '60.0000', $t, '60.0000');

        $this->fire($counting);

        self::assertSame('56.0000', $this->onHand($siro));
        self::assertSame(0, $this->correctionsFor($siro), 'nothing varies: counted 60 − sold 4 == on-hand 56');

        $item->refresh();
        self::assertContains(CountingItemFlagReason::BasketWindow->value, $item->flag_reasons ?? []);
    }

    /** A real variance ON TOP of an in-count sale still reaches stock. */
    public function test_a_real_variance_survives_an_in_count_sale_on_the_same_line(): void
    {
        $t = CarbonImmutable::now()->subHours(2);
        $siro = $this->product('SIRO-TOUX');

        // Counted 60 at T, 4 sold at T+5 → expected 56, but only 54 on hand.
        $this->setOnHand($siro, '54.0000');
        $this->movement($siro, '60.0000', '56.0000', $t->addMinutes(5));

        $counting = $this->counting(15);
        $this->item($counting, $siro, '60.0000', $t, '60.0000');

        $this->fire($counting);

        self::assertSame('56.0000', $this->onHand($siro));
        self::assertDatabaseHas('stock_movements', [
            'product_id' => $siro->id,
            'reason' => MovementReason::CountCorrection->value,
            'quantity' => '2.0000',
        ]);
    }

    /**
     * The report is the operator's only view of the count. It must state the
     * expected quantity, the counted quantity, the variance and whether the
     * variance was applied — and its summary must agree with those rows.
     */
    public function test_the_report_shows_expected_counted_variance_and_applied(): void
    {
        $t = CarbonImmutable::now()->subHours(2);

        $short = $this->product('COMP-MAGN', '10.000000');
        $exact = $this->product('CREM-SOLA', '10.000000');
        $this->setOnHand($short, '70.0000');
        $this->setOnHand($exact, '25.0000');
        $this->movement($short, '50.0000', '70.0000', $t->subMinutes(13));

        $counting = $this->counting(15);
        $this->item($counting, $short, '68.0000', $t, '70.0000');
        $this->item($counting, $exact, '25.0000', $t, '25.0000');

        $this->fire($counting);

        /** @var CountingDiscrepancyReportService $reports */
        $reports = app(CountingDiscrepancyReportService::class);
        $report = $reports->build($counting->fresh(), $this->user, []);

        /** @var array<string, mixed> $summary */
        $summary = $report['summary'];
        self::assertSame(2, $summary['total_items_counted']);
        self::assertSame(1, $summary['items_with_variance']);
        self::assertSame(1, $summary['items_no_variance']);
        self::assertSame(0, $summary['items_not_applied']);
        self::assertSame(1, $summary['items_applied']);
        self::assertSame(0, bccomp('-20.000', (string) $summary['total_variance_value']['net'], 3));

        /** @var list<array<string, mixed>> $rows */
        $rows = $report['items'];
        self::assertCount(2, $rows);

        $shortRow = null;
        foreach ($rows as $row) {
            if ($row['product']['id'] === $short->id) {
                $shortRow = $row;
            }
        }

        self::assertNotNull($shortRow);
        self::assertSame(0, bccomp('70.0000', (string) $shortRow['expected_qty'], 4));
        self::assertSame(0, bccomp('68.0000', (string) $shortRow['counted_qty'], 4));
        self::assertSame(0, bccomp('-2.0000', (string) $shortRow['variance_qty'], 4));
        self::assertTrue($shortRow['variance_applied']);
        self::assertNull($shortRow['not_applied_reason']);
    }

    /**
     * A blocked line (pending opening cost) must be reported as NOT applied —
     * the campaign's "nothing anywhere says the adjustments were not applied".
     */
    public function test_a_blocked_line_is_reported_as_not_applied(): void
    {
        $t = CarbonImmutable::now()->subHours(2);
        $this->location->update(['onboarding_mode' => true]);

        $product = $this->product('NO-COST', '0.000000');
        $this->setOnHand($product, '0.0000');

        $counting = $this->counting(15);
        $this->item($counting, $product, '12.0000', $t, '0.0000');

        $this->fire($counting);

        self::assertSame('0.0000', $this->onHand($product));

        /** @var CountingDiscrepancyReportService $reports */
        $reports = app(CountingDiscrepancyReportService::class);
        $report = $reports->build($counting->fresh(), $this->user, []);

        /** @var array<string, mixed> $summary */
        $summary = $report['summary'];
        self::assertSame(1, $summary['items_not_applied']);

        /** @var list<array<string, mixed>> $rows */
        $rows = $report['items'];
        self::assertFalse($rows[0]['variance_applied']);
        self::assertSame(
            CountingItemFlagReason::PendingOpeningCost->value,
            $rows[0]['not_applied_reason'],
        );
    }

    /**
     * W2-7 coupling: a batch-tracked shortage must draw the LOT ledger down by
     * the same delta (FEFO), and must not mint or inflate a DEFAULT lot.
     */
    public function test_a_batch_tracked_shortage_draws_down_the_lot_ledger(): void
    {
        $t = CarbonImmutable::now()->subHours(2);
        $product = $this->product('SIRO-LOT', '8.500000', batchTracked: true);
        $this->setOnHand($product, '65.0000');

        $lotA = $this->lot($product, 'LOT-A', CarbonImmutable::now()->addDays(30)->toDateString(), '30.0000');
        $default = $this->lot($product, BatchStockService::DEFAULT_BATCH_NUMBER, CarbonImmutable::now()->addDays(365)->toDateString(), '35.0000');

        $counting = $this->counting(15);
        $this->item($counting, $product, '63.0000', $t, '65.0000');

        $this->fire($counting);

        self::assertSame('63.0000', $this->onHand($product));
        // FEFO: the soonest-expiring lot absorbs the shrinkage.
        self::assertSame(0, bccomp('28.0000', $this->lotQty($lotA), 4));
        self::assertSame(0, bccomp('35.0000', $this->lotQty($default), 4));
        self::assertSame(2, Batch::query()->where('product_id', $product->id)->count(), 'no new lot may be minted by a shortage');
    }

    /**
     * The mirror: a batch-tracked overage lands in the DEFAULT lot as the
     * UNTRACKED REMAINDER (never the whole aggregate — W2-7).
     */
    public function test_a_batch_tracked_overage_tops_up_only_the_untracked_remainder(): void
    {
        $t = CarbonImmutable::now()->subHours(2);
        $product = $this->product('SIRO-LOT', '8.500000', batchTracked: true);
        $this->setOnHand($product, '65.0000');

        $lotA = $this->lot($product, 'LOT-A', CarbonImmutable::now()->addDays(30)->toDateString(), '30.0000');
        $default = $this->lot($product, BatchStockService::DEFAULT_BATCH_NUMBER, CarbonImmutable::now()->addDays(365)->toDateString(), '35.0000');

        $counting = $this->counting(15);
        $this->item($counting, $product, '67.0000', $t, '65.0000');

        $this->fire($counting);

        self::assertSame('67.0000', $this->onHand($product));
        self::assertSame(0, bccomp('30.0000', $this->lotQty($lotA), 4));
        self::assertSame(0, bccomp('37.0000', $this->lotQty($default), 4));
        self::assertSame(2, Batch::query()->where('product_id', $product->id)->count());
    }

    /** @param  numeric-string  $quantity */
    private function lot(Product $product, string $batchNumber, string $expiryDate, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacturing_date' => CarbonImmutable::now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    /** @return numeric-string */
    private function lotQty(Batch $batch): string
    {
        /** @var numeric-string $qty */
        $qty = (string) (BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->value('quantity') ?? '0');

        return $qty;
    }
}
