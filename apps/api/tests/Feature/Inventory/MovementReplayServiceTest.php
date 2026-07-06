<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\MovementReplayService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task B1 — MovementReplayService is the reconciliation math core for
 * live-inventory-counting: it sums signed per-row stock_movement deltas
 * (quantity_after - quantity_before) inside a (from, to] window keyed on
 * COALESCE(occurred_at, created_at), classifying by the ROW delta only —
 * never by MovementType (Adjustment is bidirectional; reversal rows
 * self-cancel through their own before/after, not through movement_type).
 */
final class MovementReplayServiceTest extends TestCase
{
    use RefreshDatabase;

    private MovementReplayService $service;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(MovementReplayService::class);

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $this->productId = $product->id;
    }

    /**
     * Creates a stock_movements row with explicit before/after quantities and
     * event time, bypassing the higher-level services so the test controls
     * the exact signed delta and occurred_at independently of movement_type.
     */
    private function movement(
        string $quantityBefore,
        string $quantityAfter,
        CarbonImmutable $occurredAt,
        ?string $variantId = null,
        MovementType $movementType = MovementType::Adjustment,
        ?string $reversesMovementId = null,
        ?string $locationId = null,
        ?string $productId = null,
    ): StockMovement {
        // `quantity`'s sign convention is inconsistent across writers (see
        // StockMovement docblock) and irrelevant to replay math, which uses
        // only quantity_before/quantity_after. Store the signed delta.
        return StockMovement::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId ?? $this->productId,
            'variant_id' => $variantId,
            'location_id' => $locationId ?? $this->locationId,
            'movement_type' => $movementType,
            'reason' => MovementReason::CountCorrection,
            'quantity' => bcsub($quantityAfter, $quantityBefore, 4),
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_sale_after_t_is_negative(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        // Sale occurs AFTER T: stock goes down by 3.
        $this->movement('10.0000', '7.0000', $t->addHour(), movementType: MovementType::Issue);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $t->addDay(),
        );

        $this->assertSame('-3.0000', $delta);
    }

    public function test_sale_before_t_is_excluded(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        // Sale occurs BEFORE the window opens - must not be counted.
        $this->movement('10.0000', '7.0000', $t->subHour(), movementType: MovementType::Issue);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $t->addDay(),
        );

        $this->assertSame('0.0000', $delta);
    }

    public function test_receipt_after_t_is_positive(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        $this->movement('5.0000', '15.0000', $t->addHour(), movementType: MovementType::Receipt);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $t->addDay(),
        );

        $this->assertSame('10.0000', $delta);
    }

    public function test_adjustment_down_after_t_is_negative(): void
    {
        // Adjustment is bidirectional - MovementType::Adjustment.isInbound()
        // returns true, but the ROW here goes DOWN. The service must classify
        // by the actual before/after delta, never by MovementType.
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        $this->assertTrue(MovementType::Adjustment->isInbound(), 'sanity: Adjustment is classified inbound by type');

        $this->movement('20.0000', '12.0000', $t->addHour(), movementType: MovementType::Adjustment);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $t->addDay(),
        );

        $this->assertSame('-8.0000', $delta);
    }

    public function test_reversal_pair_nets_zero(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        $original = $this->movement('10.0000', '8.0000', $t->addHour(), movementType: MovementType::Issue);

        // The reversal row has its OWN before/after that undoes the original
        // row's effect; it self-cancels through its own delta, not through
        // movement_type inspection.
        StockMovement::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'movement_type' => MovementType::Adjustment,
            'reason' => MovementReason::CountCorrection,
            'quantity' => '2.0000',
            'quantity_before' => '8.0000',
            'quantity_after' => '10.0000',
            'occurred_at' => $t->addHours(2),
            'reverses_movement_id' => $original->id,
        ]);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $t->addDay(),
        );

        $this->assertSame('0.0000', $delta);
    }

    public function test_boundary_exactly_at_from_is_excluded_exactly_at_to_is_included(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');
        $end = $t->addDay();

        // Exactly at `from` - must be excluded (window is (from, to]).
        $this->movement('10.0000', '11.0000', $t, movementType: MovementType::Receipt);

        // Exactly at `to` - must be included.
        $this->movement('11.0000', '13.0000', $end, movementType: MovementType::Receipt);

        $delta = $this->service->signedDelta(
            $this->productId,
            $this->locationId,
            null,
            $t,
            $end,
        );

        $this->assertSame('2.0000', $delta, 'only the at-to receipt (+2) should count; the at-from receipt (+1) must be excluded');
    }

    public function test_variant_isolation(): void
    {
        $t = CarbonImmutable::parse('2026-07-01 10:00:00');

        $variantA = ProductVariant::factory()->create([
            'product_id' => $this->productId,
        ]);
        $variantB = ProductVariant::factory()->create([
            'product_id' => $this->productId,
        ]);

        // Null-variant line (the product's own base stock).
        $this->movement('10.0000', '9.0000', $t->addHour(), variantId: null, movementType: MovementType::Issue);
        // Variant A's own movement.
        $this->movement('5.0000', '3.0000', $t->addHour(), variantId: $variantA->id, movementType: MovementType::Issue);
        // Variant B's movement - must be invisible to A and to the null line.
        $this->movement('5.0000', '1.0000', $t->addHour(), variantId: $variantB->id, movementType: MovementType::Issue);

        $deltaNull = $this->service->signedDelta($this->productId, $this->locationId, null, $t, $t->addDay());
        $deltaA = $this->service->signedDelta($this->productId, $this->locationId, $variantA->id, $t, $t->addDay());
        $deltaB = $this->service->signedDelta($this->productId, $this->locationId, $variantB->id, $t, $t->addDay());

        $this->assertSame('-1.0000', $deltaNull);
        $this->assertSame('-2.0000', $deltaA);
        $this->assertSame('-4.0000', $deltaB);
    }

    public function test_has_movement_near_within_window(): void
    {
        $instant = CarbonImmutable::parse('2026-07-01 10:00:00');

        $this->movement('10.0000', '9.0000', $instant->addMinutes(4), movementType: MovementType::Issue);

        $this->assertTrue(
            $this->service->hasMovementNear($this->productId, $this->locationId, null, $instant, 5)
        );
    }

    public function test_has_movement_near_outside_window(): void
    {
        $instant = CarbonImmutable::parse('2026-07-01 10:00:00');

        $this->movement('10.0000', '9.0000', $instant->addMinutes(10), movementType: MovementType::Issue);

        $this->assertFalse(
            $this->service->hasMovementNear($this->productId, $this->locationId, null, $instant, 5)
        );
    }
}
