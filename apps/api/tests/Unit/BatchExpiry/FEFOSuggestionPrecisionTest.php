<?php

declare(strict_types=1);

namespace Tests\Unit\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-7 — the FEFO batch-suggestion pipeline must run quantities on bcmath
 * decimal strings, never native floats (precision contract, rule 19).
 *
 * Two things are pinned here:
 *  1. TYPE — every quantity leaving the service is a canonical scale-4
 *     numeric string, so the JSON wire carries strings, not JSON numbers.
 *  2. VALUE — fractional decomposition is EXACT. The pre-fix float
 *     implementation reported `fully_fulfilled: false` with
 *     `shortfall: 1.1102230246251565e-16` for a request of 1.1 against lots
 *     [0.7, 0.4] that exactly cover it — a user-visible false shortfall, not
 *     merely a style violation.
 */
class FEFOSuggestionPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private FEFOInventoryService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Gate r4 R4-4: the service now constructor-injects ProductVariantLookup
        // (a variant-bearing product must never get a product-level DEFAULT lot),
        // so it is container-resolved rather than newed up.
        $this->service = app(FEFOInventoryService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->for($this->tenant)->for($this->company)->create();
    }

    // -------------------------------------------------------------------------
    // VALUE — exact decimal decomposition
    // -------------------------------------------------------------------------

    /**
     * The canonical float-artifact case: 1.1 − 0.7 is 0.4000000000000001 in
     * IEEE 754, so the second lot (0.4) leaves a 1.11e-16 residue and the
     * request is wrongly reported as unfulfilled.
     */
    public function test_exactly_covered_request_reports_no_shortfall(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '0.7000');
        $this->createBatchWithStock(expiryDays: 20, quantity: '0.4000');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.1000',
        );

        $this->assertTrue($result->fullyFulfilled, 'Lots [0.7, 0.4] exactly cover a request of 1.1');
        $this->assertSame('0.0000', $result->shortfall);
        $this->assertCount(2, $result->suggestions);
        $this->assertSame('0.7000', $result->suggestions[0]->quantity);
        $this->assertSame('0.4000', $result->suggestions[1]->quantity);
        $this->assertSame('1.1000', $result->getSuggestedQuantity());
    }

    /** Scale-4 fractional decomposition across three lots must be exact. */
    public function test_fractional_decomposition_across_three_lots_is_exact(): void
    {
        $this->createBatchWithStock(expiryDays: 5, quantity: '0.5000');
        $this->createBatchWithStock(expiryDays: 10, quantity: '0.5000');
        $this->createBatchWithStock(expiryDays: 15, quantity: '0.2505');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.2505',
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertSame('0.0000', $result->shortfall);
        $this->assertFalse($result->hasShortfall());
        $this->assertSame(
            ['0.5000', '0.5000', '0.2505'],
            array_map(fn ($s) => $s->quantity, $result->suggestions),
        );
        $this->assertSame('1.2505', $result->toArray()['total_quantity_suggested']);
    }

    /** Repeated 0.1 accumulation — the textbook float drift case. */
    public function test_repeated_tenth_accumulation_is_exact(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->createBatchWithStock(expiryDays: $i, quantity: '0.1000');
        }

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '0.8000',
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertSame('0.0000', $result->shortfall);
        $this->assertCount(8, $result->suggestions);
        $this->assertSame('0.8000', $result->getSuggestedQuantity());
    }

    /** A genuine shortfall is still reported, as an exact scale-4 string. */
    public function test_genuine_shortfall_is_an_exact_decimal_string(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '0.3000');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.2505',
        );

        $this->assertFalse($result->fullyFulfilled);
        $this->assertTrue($result->hasShortfall());
        $this->assertSame('0.9505', $result->shortfall);
        $this->assertSame('0.3000', $result->getSuggestedQuantity());
    }

    /**
     * Quantities far beyond float's 15-significant-digit budget must survive
     * intact — a (float) round-trip silently drops the sub-unit digits.
     */
    public function test_large_quantity_keeps_sub_unit_digits(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '99999999.1234');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '99999999.1234',
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertSame('0.0000', $result->shortfall);
        $this->assertSame('99999999.1234', $result->suggestions[0]->quantity);
    }

    /**
     * A request carrying more than 4 decimal places must be ROUNDED to the
     * canonical scale, not truncated.
     *
     * The route validator caps input at 4dp, but the in-process callers are not
     * regex-gated — StockReservationService::reserveWithFEFO() forwards whatever
     * numeric-string it is handed straight into this service. Truncating there
     * would silently under-reserve.
     */
    public function test_over_scale_request_is_rounded_not_truncated(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '5.0000');

        // 1.00005 truncates to 1.0000 but rounds (HALF_UP) to 1.0001.
        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.00005',
        );

        $this->assertSame('1.0001', $result->suggestions[0]->quantity);
        $this->assertSame('1.0001', $result->getSuggestedQuantity());
        $this->assertTrue($result->fullyFulfilled);
    }

    /** Rounding down at the boundary is equally exact. */
    public function test_over_scale_request_rounds_down_below_the_half(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '5.0000');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.00004',
        );

        $this->assertSame('1.0000', $result->suggestions[0]->quantity);
        $this->assertTrue($result->fullyFulfilled);
    }

    // -------------------------------------------------------------------------
    // TYPE — no float ever leaves the pipeline
    // -------------------------------------------------------------------------

    public function test_every_emitted_quantity_is_a_numeric_string(): void
    {
        $this->createBatchWithStock(expiryDays: 10, quantity: '2.5000');

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '4.0000',
        );

        $payload = $result->toArray();

        $this->assertIsString($payload['shortfall']);
        $this->assertIsString($payload['total_quantity_suggested']);
        $this->assertIsString($payload['suggestions'][0]['quantity']);

        // And the serialized JSON must carry them as strings, not JSON numbers.
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"shortfall":"1.5000"', $json);
        $this->assertStringContainsString('"total_quantity_suggested":"2.5000"', $json);
        $this->assertStringContainsString('"quantity":"2.5000"', $json);
    }

    /** An empty suggestion set still emits canonical scale-4 strings. */
    public function test_empty_result_emits_scale_four_strings(): void
    {
        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.2500',
        );

        $payload = $result->toArray();

        $this->assertSame([], $payload['suggestions']);
        $this->assertFalse($payload['fully_fulfilled']);
        $this->assertSame('1.2500', $payload['shortfall']);
        $this->assertSame('0.0000', $payload['total_quantity_suggested']);
    }

    /** Reserved stock is excluded using decimal arithmetic, not the float accessor. */
    public function test_reserved_quantity_is_subtracted_exactly(): void
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-RESERVED-PRECISION',
            'expiry_date' => now()->addDays(30),
            'is_active' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => '1.0000',
            'reserved_quantity' => '0.7000',   // 0.3000 available
        ]);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: '1.0000',
        );

        $this->assertFalse($result->fullyFulfilled);
        $this->assertSame('0.3000', $result->suggestions[0]->quantity);
        $this->assertSame('0.7000', $result->shortfall);
    }

    /** @param  numeric-string  $quantity */
    private function createBatchWithStock(int $expiryDays, string $quantity, bool $isRecalled = false): Batch
    {
        static $batchCounter = 0;
        $batchCounter++;

        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'PRECISION-BATCH-'.$batchCounter,
            'expiry_date' => now()->addDays($expiryDays),
            'is_active' => true,
            'is_recalled' => $isRecalled,
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
}
