<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockForFulfilmentException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Campaign defect W2-7 — confirming a sales order on a batch-tracked product
 * minted a `DEFAULT` lot seeded with the product's ENTIRE `stock_levels`
 * quantity, without subtracting the quantity already held by the real (dated)
 * lots. The batch ledger then reported twice the physical stock, and the
 * reservation landed on an expiry-less lot, defeating FEFO in the one vertical
 * (parapharmacy) where expiry control is the whole point.
 *
 * The policy these tests pin:
 *
 *   1. an implicit reservation on a batch-tracked product reserves against the
 *      **FEFO lot** (earliest expiry with availability), not against `DEFAULT`;
 *   2. the `DEFAULT` lot exists only for the **untracked remainder** —
 *      `stock_levels.quantity − Σ(real lot quantities)`, clamped at zero;
 *   3. a zero remainder mints **nothing**.
 *
 * Every quantity here is a bcmath decimal string at the canonical scale of 4
 * (rule 19); no float ever touches a quantity.
 */
final class ImplicitReservationFefoLotTest extends TestCase
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
            'name' => 'W2-7 FEFO Tenant',
            'slug' => 'w27-fefo-'.bin2hex(random_bytes(4)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W2-7 FEFO Company',
            'legal_name' => 'W2-7 FEFO Company SARL',
            'tax_id' => 'W27-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'W27-WH',
            'name' => 'W2-7 Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'W27-CREM-BEBE',
            'name' => 'Crème hydratante Bébé 200ml',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);
    }

    /**
     * Two dated lots holding the whole on-hand quantity, exactly as a goods
     * receipt with explicit lots leaves it.
     *
     * @param  numeric-string  $earlyQuantity
     * @param  numeric-string  $lateQuantity
     * @return array{0: Batch, 1: Batch}
     */
    private function seedTwoDatedLots(string $earlyQuantity, string $lateQuantity): array
    {
        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), $earlyQuantity);
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(120)->toDateString(), $lateQuantity);

        return [$early, $late];
    }

    /** @param  numeric-string  $quantity */
    private function seedLot(string $batchNumber, string $expiryDate, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacturing_date' => now()->subDay()->toDateString(),
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

    /** @param  numeric-string  $quantity */
    private function seedStockLevel(string $quantity): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    /** @return numeric-string */
    private function totalBatchQuantity(): string
    {
        /** @var numeric-string $total */
        $total = bcadd(
            (string) BatchStock::query()
                ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
                ->where('product_batches.product_id', $this->product->id)
                ->where('inventory_batch_stock.location_id', $this->location->id)
                ->sum('inventory_batch_stock.quantity'),
            '0',
            4,
        );

        return $total;
    }

    private function defaultBatch(): ?Batch
    {
        return Batch::query()
            ->where('product_id', $this->product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();
    }

    /**
     * The campaign shape: 30 units in 2 dated lots, no DEFAULT lot, confirm for
     * 5. The reservation must land on the EARLIEST-EXPIRY lot, the batch ledger
     * must still total 30, and no DEFAULT row may be minted.
     */
    public function test_implicit_reservation_with_fully_tracked_stock_reserves_the_fefo_lot_and_mints_no_default_lot(): void
    {
        [$early, $late] = $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('30.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertNull(
            $this->defaultBatch(),
            'Stock that is already fully represented by real lots leaves NO untracked '
            .'remainder, so no DEFAULT lot may be minted.',
        );

        $this->assertSame(
            $early->id,
            $reservation->batch_id,
            'The implicit reservation must pick the FEFO lot (earliest expiry), not a lot with no expiry.',
        );

        $this->assertSame(0, bccomp('30.0000', $this->totalBatchQuantity(), 4),
            'The batch ledger must still total the real on-hand quantity — never double it.');

        $earlyStock = BatchStock::where('batch_id', $early->id)->firstOrFail();
        $lateStock = BatchStock::where('batch_id', $late->id)->firstOrFail();
        $this->assertSame(0, bccomp('5.0000', (string) $earlyStock->reserved_quantity, 4));
        $this->assertSame(0, bccomp('0.0000', (string) $lateStock->reserved_quantity, 4));
    }

    /**
     * The mixed shape: 30 units in dated lots plus 3 untracked units on the
     * aggregate row. The DEFAULT lot must carry exactly the 3-unit remainder.
     */
    public function test_implicit_reservation_mints_the_default_lot_for_the_untracked_remainder_only(): void
    {
        [$early] = $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('33.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $default = $this->defaultBatch();
        $this->assertNotNull($default, 'A positive untracked remainder must be backed by the DEFAULT lot.');
        if ($default === null) {
            return;
        }

        $defaultStock = BatchStock::where('batch_id', $default->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame(
            0,
            bccomp('3.0000', (string) $defaultStock->quantity, 4),
            'DEFAULT must hold stock_levels.quantity − Σ(real lots) = 33 − 30 = 3, never the total.',
        );

        $this->assertSame(0, bccomp('33.0000', $this->totalBatchQuantity(), 4));
        $this->assertSame(
            $early->id,
            $reservation->batch_id,
            'Even with a DEFAULT lot present, FEFO must prefer the earliest-expiry dated lot.',
        );
    }

    /**
     * Guard against the inverse error: a batch ledger that already exceeds the
     * aggregate row must clamp the remainder at zero rather than compute a
     * negative target (which `ensureDefaultBatch` would silently treat as
     * "mint nothing" only by accident).
     */
    public function test_a_batch_ledger_above_the_aggregate_row_clamps_the_remainder_at_zero(): void
    {
        $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('25.0000');

        app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertNull($this->defaultBatch());
        $this->assertSame(0, bccomp('30.0000', $this->totalBatchQuantity(), 4));
    }

    /**
     * 🚨 The oversell hole behind F11's "stock_levels.reserved stayed 0.0000".
     *
     * A batch-booked reservation increments the LOT's `reserved_quantity` and
     * NEVER `stock_levels.reserved` (`StockReservationService.php:186-194` writes
     * one OR the other). The aggregate branch then computed availability as
     * `quantity − reserved`, so it could not see a single unit of the stock the
     * lot-booked reservations were already holding.
     *
     * Reachable straight from sales-order confirm: confirm the whole lot, then
     * confirm the same quantity again. The second confirm finds no single lot
     * with availability (the first hold consumed it), falls through to the
     * aggregate branch, reads `30 − 0 = 30` available and GRANTS a second hold on
     * stock that is already fully spoken for — 60 units reserved against 30.
     */
    public function test_a_second_confirm_cannot_oversell_stock_already_held_by_a_lot_booked_reservation(): void
    {
        $this->seedTwoDatedLots('30.0000', '0.0000');
        $this->seedStockLevel('30.0000');

        $first = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '30.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertNotNull($first->batch_id, 'precondition: the first hold is booked on a lot, not the aggregate');

        $this->expectException(InsufficientStockForFulfilmentException::class);

        app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '30.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );
    }

    /**
     * 🚨 Campaign wave 4, W2-7 NEW SHAPE — the defect also manifests as an
     * OVERWRITE, not only as a fresh phantom row.
     *
     * Wave 4 found a `DEFAULT` lot that already held 15.0000 and a sales-order
     * confirm that raised it to 65.0000 — the tuple's whole aggregate — leaving a
     * lot ledger of 115 against `stock_levels` 65. `ensureDefaultBatch()` tops the
     * lot UP TO its target, so passing the aggregate overwrites a correct existing
     * value just as surely as it seeds a new one.
     *
     * Wave 4's exact numbers: real lots 30 + 20, DEFAULT already at 15, aggregate
     * 65. The remainder is 65 − 50 = 15, so the correct answer is that the
     * pre-existing DEFAULT lot is left EXACTLY where it is.
     */
    public function test_a_pre_existing_default_lot_is_not_overwritten_with_the_aggregate(): void
    {
        $early = $this->seedLot('LOT-A', now()->addDays(30)->toDateString(), '30.0000');
        $this->seedLot('LOT-C', now()->addDays(120)->toDateString(), '20.0000');
        $existingDefault = $this->seedLot(
            BatchStockService::DEFAULT_BATCH_NUMBER,
            now()->addDays(365)->toDateString(),
            '15.0000',
        );
        $this->seedStockLevel('65.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertSame(
            0,
            bccomp('15.0000', (string) BatchStock::where('batch_id', $existingDefault->id)->value('quantity'), 4),
            'The existing DEFAULT lot already held the untracked remainder (65 − 50); confirm must leave it alone, '
            .'never raise it to the 65-unit aggregate.',
        );
        $this->assertSame(0, bccomp('65.0000', $this->totalBatchQuantity(), 4),
            'Σ lots must equal stock_levels — wave 4 saw 115 against 65.');
        $this->assertSame($early->id, $reservation->batch_id);
    }

    /**
     * The other direction of the same shape: an ALREADY-OVER-SEEDED `DEFAULT` lot
     * (the phantom this lane repairs) is left alone by the reservation path — it
     * is neither raised nor SHRUNK.
     *
     * Not an oversight. Reducing a lot's quantity is a stock quantity change and
     * needs its own justifying movement (document-per-action); doing it as a
     * silent side effect of confirming an order is exactly the class of write this
     * campaign is remediating. The shrink belongs to
     * `inventory:repair-phantom-default-batches`, which writes a `stock_movements`
     * + `inventory_batch_movements` pair for every unit it removes. What confirm
     * MUST guarantee is that it never makes the over-count worse — that is what
     * this test pins.
     */
    public function test_an_over_seeded_default_lot_is_neither_raised_nor_silently_shrunk_by_a_confirm(): void
    {
        $this->seedLot('LOT-A', now()->addDays(30)->toDateString(), '30.0000');
        $phantom = $this->seedLot(
            BatchStockService::DEFAULT_BATCH_NUMBER,
            now()->addDays(365)->toDateString(),
            '30.0000',
        );
        $this->seedStockLevel('30.0000');

        app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertSame(
            0,
            bccomp('30.0000', (string) BatchStock::where('batch_id', $phantom->id)->value('quantity'), 4),
            'confirm must not make the over-count worse, and must not silently correct it either',
        );
    }

    /**
     * 🚨 Gate r2 C-3 — the hoisted tuple lookup must be VARIANT-SCOPED.
     *
     * `StockLevel::where(product, location, company)->first()` with no
     * `variant_id` predicate returns whichever row the storage engine hands back
     * first. On `dev` that blind lookup was reached only by the aggregate branch
     * and never by a batch-tracked product (the phantom DEFAULT lot always
     * resolved), so it was harmless. This lane hoists it in FRONT OF BOTH
     * BRANCHES, which turns it into a false-refusal surface: with an empty
     * variant row physically ahead of the product-level row, a 5-of-5 confirm was
     * REFUSED ("Available: 0.0000, Requested: 5.0000") where `dev` GRANTED it —
     * and the outcome depended on row order, i.e. it was arbitrary.
     *
     * The predicate now matches the resolver
     * (`resolveDefaultBatchIdForImplicitReservation()`) and the codebase's
     * canonical variant-scoped `stock_levels` lock in
     * `WeightedAverageCostService`. The reservation sum is scoped the same way, so
     * the guard cannot compare a product-level quantity against holds summed
     * across every variant.
     */
    public function test_an_empty_variant_row_ahead_of_the_product_level_row_does_not_refuse_the_confirm(): void
    {
        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'V-200ML',
            'sku' => 'W27-CREM-200',
            'name_suffix' => '200ml',
        ]);

        // Inserted FIRST, and empty — this is the row a variant-blind `first()`
        // picks up, and it reports zero available.
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => '0.0000',
            'reserved' => '0.0000',
        ]);

        $lot = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '5.0000');
        $this->seedStockLevel('5.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertSame($lot->id, $reservation->batch_id, 'the product-level tuple covers this in full');
    }

    /**
     * Gate r2 C-3, second half — the reservation SUM must carry the same variant
     * predicate as the quantity it is subtracted from. Summing holds across every
     * variant while reading one row's quantity compares two different tuples.
     */
    public function test_a_sibling_variants_hold_does_not_reduce_the_product_level_availability(): void
    {
        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'V-200ML',
            'sku' => 'W27-CREM-200',
            'name_suffix' => '200ml',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => '20.0000',
            'reserved' => '0.0000',
        ]);

        // A hold that belongs to the SIBLING variant, not to the product-level tuple.
        StockReservation::create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variant->id,
            'location_id' => $this->location->id,
            'quantity' => '20.0000',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => (string) Str::uuid(),
            'priority' => 0,
        ]);

        $lot = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '5.0000');
        $this->seedStockLevel('5.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertSame($lot->id, $reservation->batch_id);
    }

    /**
     * 🚨 Gate r1 CRITICAL 1 — the MIRROR of the hole above, in the
     * aggregate-then-lot order, and a REGRESSION this lane introduced.
     *
     * `reserve()` writes the hold to the LOT or to `stock_levels.reserved`, never
     * both, so the two branches each saw only half the truth. The addendum taught
     * the aggregate branch to see lot-booked holds; the batch branch still read
     * `batchStock.quantity − batchStock.reserved_quantity` alone. Because the FEFO
     * resolver now returns null whenever no single lot covers the request, a
     * batch-tracked product can hold a MIX of aggregate-booked and lot-booked
     * reservations — a state the phantom DEFAULT lot used to make impossible.
     *
     * Exploit: 18 + 12 in two dated lots, aggregate 30. Reserve 25 → no single lot
     * covers → aggregate hold of 25. Reserve 12 → the 12-unit lot still reads
     * `12 − 0 = 12` available, because no lot ever saw the 25. Granted: 37 units
     * reserved against 30 on hand.
     *
     * The hold is now computed ONCE, before the branch, and both branches refuse
     * against it.
     */
    public function test_a_lot_booked_confirm_cannot_oversell_stock_already_held_by_an_aggregate_booked_reservation(): void
    {
        $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('30.0000');

        $spill = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '25.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertNull($spill->batch_id, 'precondition: the first hold spills over and is booked on the aggregate');

        $this->expectException(InsufficientStockForFulfilmentException::class);

        app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '12.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );
    }

    /**
     * The same guard must not refuse a request the tuple genuinely covers: after
     * the 25-unit aggregate hold, 5 units are still free and a 5-unit request
     * must be GRANTED — on a lot, since the 12-unit lot can cover it.
     */
    public function test_the_tuple_level_hold_still_grants_what_the_stock_genuinely_covers(): void
    {
        [$early] = $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('30.0000');

        app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '25.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $second = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertSame($early->id, $second->batch_id, 'FEFO still picks the earliest-expiry lot; the tuple guard is what caps the total.');
        $this->assertSame(
            0,
            bccomp('30.0000', bcadd('25.0000', '5.0000', 4), 4),
            'the two holds together are exactly the on-hand quantity — neither may be refused',
        );
    }

    /**
     * A request that no single lot can cover falls back to the AGGREGATE
     * reservation rather than over-drawing one lot: `reserve()` writes exactly
     * one row with one `batch_id`, so a spill-over request cannot be lot-pinned
     * without splitting it. The aggregate branch still enforces
     * `stock_levels` availability, and issuance stays FEFO
     * (`FEFOInventoryService::consumeBatchesAtomically`).
     */
    public function test_a_request_no_single_lot_can_cover_falls_back_to_the_aggregate_reservation(): void
    {
        $this->seedTwoDatedLots('18.0000', '12.0000');
        $this->seedStockLevel('30.0000');

        $reservation = app(StockReservationService::class)->reserve(
            company: $this->company,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '25.0000',
            sourceType: ReservationSource::SalesOrder,
            sourceId: (string) Str::uuid(),
        );

        $this->assertNull($reservation->batch_id);
        $this->assertNull($this->defaultBatch());
        $this->assertSame(0, bccomp('30.0000', $this->totalBatchQuantity(), 4));
        $this->assertSame(
            0,
            bccomp('25.0000', (string) StockLevel::where('product_id', $this->product->id)->value('reserved'), 4),
        );
    }
}
