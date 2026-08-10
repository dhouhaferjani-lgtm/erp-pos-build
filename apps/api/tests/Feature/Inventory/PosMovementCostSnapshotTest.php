<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\Product\Domain\Product;
use Database\Factories\CompanyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\BuildsPosSaleReceiptEvents;
use Tests\Traits\BuildsWave3ExitFixtures;

/**
 * DPA Wave 3 · sub-wave 3A · **T5 — snapshot cost on the TWO LIVE POS writers,
 * resolver-independently**.
 *
 * `PosCoreReceiptProjection::decrementStock` / `::restockStock` wrote movements
 * with `unit_cost`/`total_cost` NULL (pinned by T1(c)). The Wave-3 exit seam
 * reads the cost from the MOVEMENT ROW, so an uncosted POS movement books no
 * COGS at all.
 *
 * ## Why the cost cannot come from `WeightedAverageCostService` (§0.1)
 *
 * `WeightedAverageCostService::scale()` calls the BARE no-arg
 * `CurrencyScaleResolver::getScale()`, which throws `UnboundCompanyContextException`
 * when no company is bound, and every WAC entry point routes through it. The
 * projection runs in a queue worker with NO `CompanyContext` (house rule 20), so
 * routing the snapshot through WAC would fail closed on the real path and pass
 * only in tests that bind a context in `setUp` — which is exactly the mistake
 * this program has already been burned by. The cost columns are therefore
 * written at the constant `COST_SCALE = 6`, resolver-independently, exactly as
 * `StockAdjustmentService::recordMovement()` already does.
 *
 * Every test here CLEARS the company context immediately before `apply()`.
 *
 * ## Scope correction carried from the plan
 *
 * T5's Revision-2 text drops `ReceiptCreationService` (fiscal I-1 / §0b.8): both
 * of its entry points return 410 Gone and the chokepoint manifest records it as
 * disposition (b), body never invoked. This suite covers the TWO live writers.
 */
final class PosMovementCostSnapshotTest extends TestCase
{
    use BuildsPosSaleReceiptEvents;
    use BuildsWave3ExitFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWave3ExitFixtures();
    }

    public function test_a_pos_sale_movement_carries_the_products_cost_at_six_decimals(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $this->project($this->posSaleReceiptEvent($product->id, null, '2'));

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertSame('7.500000', $this->numericString($movement->unit_cost));
        self::assertSame('15.000000', $this->numericString($movement->total_cost));
    }

    public function test_a_refund_restock_movement_carries_the_same_cost(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $sale = $this->posSaleReceiptEvent($product->id, null, '2');
        $this->project($sale);
        $this->project($this->posRefundReceiptEvent($sale, $product->id, null, '1'));

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_return')->firstOrFail();

        self::assertSame('7.500000', $this->numericString($movement->unit_cost));
        self::assertSame('7.500000', $this->numericString($movement->total_cost));
    }

    /**
     * The single most load-bearing assertion in this task: the projection runs in
     * a queue worker with no `CompanyContext`. A snapshot routed through the WAC
     * service (or any bare no-arg `getScale()`) throws here.
     */
    public function test_the_snapshot_survives_a_worker_with_no_company_context(): void
    {
        $product = $this->physicalProduct(costPrice: '3.333333');
        $this->seedStock($product->id, '10.0000');

        $event = $this->posSaleReceiptEvent($product->id, null, '3');

        app(CompanyContext::class)->clear();
        self::assertFalse(app(CompanyContext::class)->hasCompany());

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertSame('3.333333', $this->numericString($movement->unit_cost));
        // 3 x 3.333333 = 9.999999 — exact at COST_SCALE, and NOT the 2-dp
        // currency-scaled 10.00 a resolver-dependent path would have produced.
        self::assertSame('9.999999', $this->numericString($movement->total_cost));
    }

    /**
     * The POS path does not re-average — claiming it does would be the V8
     * mistake. `avg_cost_before` / `avg_cost_after` stay NULL.
     */
    public function test_the_pos_path_does_not_touch_the_running_average(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $this->project($this->posSaleReceiptEvent($product->id, null, '2'));

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertNull($movement->avg_cost_before);
        self::assertNull($movement->avg_cost_after);
        self::assertSame('7.500000', $this->numericString($product->fresh()?->cost_price));
    }

    /**
     * The projection is replayed on retry. `unit_cost` must be written by the
     * SAME idempotent insert as the movement — never by a follow-up UPDATE — or
     * a replay double-counts.
     */
    public function test_a_replay_writes_no_second_costed_movement(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $event = $this->posSaleReceiptEvent($product->id, null, '2');
        $this->project($event);
        $this->project($event);

        self::assertSame(1, StockMovement::query()->where('reason', 'pos_sale')->count());

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();
        self::assertSame('15.000000', $this->numericString($movement->total_cost));
    }

    public function test_a_zero_cost_product_still_produces_a_costed_row_of_zero(): void
    {
        // Not an error: the destruction/sale still has to be RECORDED. A zero
        // amount posts no journal entry downstream, which is the seam's job to
        // decide — not this writer's.
        $product = $this->physicalProduct(costPrice: '0.000000');
        $this->seedStock($product->id, '10.0000');

        $this->project($this->posSaleReceiptEvent($product->id, null, '2'));

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertSame('0.000000', $this->numericString($movement->unit_cost));
        self::assertSame('0.000000', $this->numericString($movement->total_cost));
    }

    /**
     * Fix round 1 · fiscal gate P2-1.
     *
     * A POS sale is authored on the device and projected LATER — on sync, on a
     * retry, on a replay of a queue that backed up. Between those two moments a
     * merchandiser may retire the product, which on this schema is a SOFT delete.
     * The snapshot resolved the product through the default (soft-delete-scoped)
     * query, so the retired product came back NULL and the movement was written
     * — and the stock decremented — with `unit_cost = 0.000000` **permanently**.
     * 3C reads the COGS basis off that row, so the effect is an understated COGS
     * and an overstated margin whose only evidence is a log line.
     *
     * `withTrashed()`: a soft-deleted product is a RESOLVABLE historical fact and
     * its cost is exactly the cost that applied when the sale happened. The null
     * branch below stays for the genuinely unresolvable case.
     */
    public function test_a_soft_deleted_product_still_yields_its_historical_cost(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        // The event is authored while the product is live…
        $event = $this->posSaleReceiptEvent($product->id, null, '2');

        // …and the product is retired before the projection ever runs.
        $product->delete();
        self::assertTrue($product->trashed());

        $this->project($event);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertSame('7.500000', $this->numericString($movement->unit_cost));
        self::assertSame('15.000000', $this->numericString($movement->total_cost));
    }

    /**
     * …and the fail-soft branch survives for a product that genuinely cannot be
     * resolved (a forged or foreign `product_id`): zero cost, a warning, and NO
     * exception — a projector may never reject an already-signed fiscal event.
     */
    public function test_a_genuinely_unresolvable_product_still_fails_soft_to_zero(): void
    {
        $product = $this->physicalProduct(costPrice: '7.500000');
        $this->seedStock($product->id, '20.0000');

        $event = $this->posSaleReceiptEvent($product->id, null, '2');

        // Re-parent the product to a FOREIGN company: the scoped lookup — the
        // api.document.010-shaped guard the snapshot carries — cannot resolve it
        // under any `withTrashed()`, while the `stock_levels` row (company-keyed)
        // survives so the writer still reaches the insert. This is the forged /
        // cross-company shape the null branch exists for.
        /** @var Company $foreign */
        $foreign = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        DB::table('products')->where('id', $product->id)->update(['company_id' => $foreign->id]);

        /** @var list<string> $warnings */
        $warnings = [];
        Log::listen(static function (MessageLogged $logged) use (&$warnings): void {
            if ($logged->level === 'warning') {
                $warnings[] = $logged->message;
            }
        });

        $this->project($event);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->where('reason', 'pos_sale')->firstOrFail();

        self::assertSame('0.000000', $this->numericString($movement->unit_cost));
        self::assertSame('0.000000', $this->numericString($movement->total_cost));

        self::assertNotEmpty(array_filter(
            $warnings,
            static fn (string $message): bool => str_contains($message, 'unresolvable for the movement cost snapshot'),
        ), 'the miss must be observable — a silent zero cost is the whole defect');
    }

    // =================================================================
    // The rename + dead-arm deletion (inv M-5)
    // =================================================================

    /**
     * `resolveWriteOffUnitCost()` becomes `resolveMovementUnitCost()` and loses
     * its first fallback arm, `weighted_average_cost` — which is NOT a column
     * (`grep weighted_average_cost database/migrations` = zero), has no accessor,
     * and whose own docblock called it a "virtual accessor hook, if ever added"
     * (§0b.7). It was permanently null, so deleting it changes no resolved value.
     */
    public function test_the_renamed_resolver_returns_the_same_value_for_every_shape(): void
    {
        $withCost = $this->physicalProduct(costPrice: '12.345678');
        self::assertSame('12.345678', $withCost->resolveMovementUnitCost());

        $zeroCost = $this->physicalProduct(costPrice: '0.000000');
        self::assertSame('0.000000', $zeroCost->resolveMovementUnitCost());

        // The remaining `?? '0.00'` arm is only reachable for an IN-MEMORY model:
        // `products.cost_price` is NOT NULL in the schema (an UPDATE to null is
        // refused by PostgreSQL), so no persisted row can take it. Recorded
        // rather than removed — the arm is the safety net for a model built but
        // not yet saved, which is how a resolver gets called during import.
        self::assertSame('0.00', (new Product)->resolveMovementUnitCost());

        self::assertSame(
            'NO',
            DB::selectOne(
                "select is_nullable from information_schema.columns
                 where table_name = 'products' and column_name = 'cost_price'"
            )->is_nullable ?? 'NO',
            'products.cost_price is NOT NULL — the null arm is in-memory only',
        );
    }

    public function test_the_old_method_name_is_gone(): void
    {
        // Reflection, not method_exists(Product::class, ...): PHPStan statically
        // proves the second call always true and (correctly) refuses it.
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Product::class))->getMethods(),
        );

        self::assertNotContains(
            'resolveWriteOffUnitCost',
            $methods,
            'the rename must be complete — two names for one cost definition is how V10-I5 happened',
        );
        self::assertContains('resolveMovementUnitCost', $methods);

        // …and no app/ CALL SITE still names the old method. (The historical
        // note in Product's docblock mentions the old name deliberately, so the
        // guard matches an invocation `->resolveWriteOffUnitCost(`, not the word.)
        $hits = shell_exec(
            'grep -rl -- "->resolveWriteOffUnitCost(" '.escapeshellarg(base_path('app')).' 2>/dev/null'
        );
        self::assertSame('', trim((string) $hits), 'no app/ caller may still invoke the old name');
    }

    private function project(FiscalEvent $event): void
    {
        // House rule 20: the worker carries NO CompanyContext. Binding one in
        // setUp would mask a regression that reintroduced a resolver dependency.
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }
}
