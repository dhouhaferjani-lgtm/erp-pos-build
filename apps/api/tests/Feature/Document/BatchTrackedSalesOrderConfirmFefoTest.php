<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * Campaign defect W2-7, at the ROUTE the campaign actually exercised:
 * `POST /api/v1/orders/{id}/confirm`.
 *
 * `SalesOrderService::confirm()` calls `StockReservationService::reserve()` once
 * per physical line with no explicit `batch_id`
 * (`app/Modules/Document/Domain/Services/SalesOrderService.php:144-155`), so the
 * implicit-lot resolution is what the operator's confirm click reaches. On base
 * that resolution minted a `DEFAULT` lot holding the product's whole on-hand
 * quantity, and the batch ledger reported 60 units for 30 physical ones.
 */
final class BatchTrackedSalesOrderConfirmFefoTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();

        // The parapharmacy default — `Product.php` forces this on for the whole
        // vertical, which is why W2-7 was reachable on every imported product.
        $this->dpProduct->update([
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        // 30 physical units, entirely inside two dated lots — see `seedLot()` for
        // what "as a goods receipt leaves the tuple" actually means (gate r4 R4-11:
        // the old wording here was false and is what hid R4-1 for a whole round).
        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('product_id', $this->dpProduct->id)
            ->update(['quantity' => '30.0000', 'reserved' => '0.0000']);
    }

    /**
     * A lot seeded THE WAY A GOODS RECEIPT SEEDS ONE — batch row, batch stock,
     * AND the positive `inventory_batch_movements` leg keyed to a real receipt
     * `stock_movements` row.
     *
     * 🚨 Gate r4 R4-11/R4-1. This helper used to write the batch row and the
     * batch-stock row with `BatchStock::create()` and NO ledger leg, while its
     * comment claimed it was "exactly as a goods receipt with explicit lots leaves
     * the tuple". That was false — `GoodsReceiptService` goes through
     * `BatchStockService::receiveBatchStock(movementId: …)`, which writes a
     * POSITIVE leg for the received quantity — and the difference is not cosmetic:
     * it is precisely what made the R3-1 restore arm look correct for a whole
     * round while being dead on every real lot. A legless fixture cannot
     * discriminate an origin-lot credit from a DEFAULT-lot fallback.
     *
     * @param  numeric-string  $quantity
     */
    private function seedLot(string $batchNumber, string $expiryDate, string $quantity): Batch
    {
        $batch = app(BatchStockService::class)->findOrCreateBatch(
            companyId: $this->dpCompany->id,
            tenantId: $this->dpTenant->id,
            productId: $this->dpProduct->id,
            batchNumber: $batchNumber,
            expiryDate: $expiryDate,
            manufacturingDate: now()->subDay()->toDateString(),
        );

        // The receipt movement the lot's inbound leg hangs off — the same shape
        // `GoodsReceiptService` produces.
        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => $quantity,
            'quantity_before' => '0.0000',
            'quantity_after' => $quantity,
            'reference' => 'GRN-'.$batchNumber,
            'occurred_at' => now()->subDay(),
        ]);

        app(BatchStockService::class)->receiveBatchStock(
            tenantId: $this->dpTenant->id,
            batchId: $batch->id,
            locationId: $this->dpLocation->id,
            quantity: $quantity,
            movementId: $movement->id,
        );

        return $batch;
    }

    /** @param  numeric-string  $quantity */
    private function draftSalesOrderFor(string $quantity): Document
    {
        $order = Document::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'W27-SO-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'location_id' => $this->dpLocation->id,
            'currency' => 'TND',
            'subtotal' => '500.000',
            'tax_amount' => '0.000',
            'total' => '500.000',
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::SalesOrder),
        ]);

        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => 'Crème hydratante Bébé 200ml',
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '500.000',
        ]);

        return $order->refresh();
    }

    /** @return numeric-string */
    private function totalBatchQuantity(): string
    {
        /** @var numeric-string $total */
        $total = bcadd(
            (string) BatchStock::query()
                ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
                ->where('product_batches.product_id', $this->dpProduct->id)
                ->sum('inventory_batch_stock.quantity'),
            '0',
            4,
        );

        return $total;
    }

    public function test_confirming_a_sales_order_reserves_the_fefo_lot_and_does_not_duplicate_the_batch_ledger(): void
    {
        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $order = $this->draftSalesOrderFor('5.0000');

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(DocumentStatus::Confirmed, $order->refresh()->status);

        $this->assertSame(
            0,
            Batch::query()
                ->where('product_id', $this->dpProduct->id)
                ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                ->count(),
            'Confirming an order must not mint a phantom DEFAULT lot for stock the dated lots already hold.',
        );

        $this->assertSame(
            0,
            bccomp('30.0000', $this->totalBatchQuantity(), 4),
            'The batch ledger must still total the 30 physical units — W2-7 made it read 60.',
        );

        $reservation = StockReservation::query()
            ->where('source_id', $order->id)
            ->sole();

        $this->assertSame($early->id, $reservation->batch_id, 'FEFO: the earliest-expiry lot wins.');
        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $early->id)->value('reserved_quantity'), 4),
        );
        $this->assertSame(
            0,
            bccomp('0.0000', (string) BatchStock::where('batch_id', $late->id)->value('reserved_quantity'), 4),
        );
    }

    public function test_confirming_a_sales_order_backs_only_the_untracked_remainder_with_a_default_lot(): void
    {
        $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        // Three units on the aggregate row that no lot accounts for.
        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('product_id', $this->dpProduct->id)
            ->update(['quantity' => '33.0000']);

        $order = $this->draftSalesOrderFor('5.0000');

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertStatus(200);

        $default = Batch::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();

        $this->assertNotNull($default);
        if ($default === null) {
            return;
        }

        $this->assertSame(
            0,
            bccomp('3.0000', (string) BatchStock::where('batch_id', $default->id)->value('quantity'), 4),
            'DEFAULT = 33 − 30 = 3 exactly.',
        );
        $this->assertSame(0, bccomp('33.0000', $this->totalBatchQuantity(), 4));
    }

    /**
     * 🚨 Campaign wave 4, W4-5 (P1) — outbound sales never decremented a lot on
     * the DOCUMENT channel: a confirmed delivery note produced an `issue`
     * movement and **no** `inventory_batch_movements` row, so after a day's
     * trading `SIRO-TOUX_150` read `stock_levels` 53 against a lot ledger of 115.
     *
     * For a vertical that forces batch tracking on every product this is fatal:
     * lot-level stock only ever grows, dated lots never deplete so FEFO ranks
     * stale lots forever, the DEFAULT lot stops backing genuinely untracked stock
     * (the remainder clamps at 0), and there is no which-lot-went-to-which-customer
     * trail — the exact record a recall needs.
     *
     * A DN line with no `batch_id` on a batch-tracked product now consumes FEFO
     * lots, each with its own `inventory_batch_movements` leg keyed to the issue
     * movement, so `Σ lots == stock_levels.quantity` after the sale.
     */
    public function test_delivery_note_confirm_consumes_fefo_lots_when_the_line_carries_no_batch_id(): void
    {
        $this->skipUnlessPostgres();

        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);

        $this->assertSame(DocumentStatus::Confirmed, $deliveryNote->refresh()->status);

        // The aggregate moved 30 -> 25 …
        $this->assertSame(
            0,
            bccomp('25.0000', (string) StockLevel::query()
                ->where('product_id', $this->dpProduct->id)
                ->value('quantity'), 4),
        );

        // … and so did the LOT ledger, FEFO-first.
        $this->assertSame(
            0,
            bccomp('13.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4),
            'the earliest-expiry lot is drawn first',
        );
        $this->assertSame(
            0,
            bccomp('12.0000', (string) BatchStock::where('batch_id', $late->id)->value('quantity'), 4),
            'the later lot is untouched — 5 fits inside the first',
        );

        $this->assertSame(
            0,
            bccomp('25.0000', $this->totalBatchQuantity(), 4),
            'Σ lots must equal stock_levels after the sale — wave 4 saw 115 against 53.',
        );

        // Document-per-action: the lot leg is keyed to the issue movement.
        $issue = StockMovement::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('movement_type', MovementType::Issue)
            ->sole();

        $legs = BatchMovement::query()->where('movement_id', $issue->id)->get();
        $this->assertCount(1, $legs, 'one leg per lot consumed');
        $this->assertSame($early->id, (int) $legs->first()?->batch_id);
        $this->assertSame(0, bccomp('-5.0000', (string) $legs->first()?->quantity, 4));
    }

    /**
     * The spill-over shape: a quantity no single lot covers is drawn from several
     * lots in expiry order, each with its own leg.
     */
    public function test_delivery_note_confirm_spills_across_lots_in_fefo_order(): void
    {
        $this->skipUnlessPostgres();

        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('25.0000')]);

        $this->assertSame(0, bccomp('0.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('5.0000', (string) BatchStock::where('batch_id', $late->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('5.0000', $this->totalBatchQuantity(), 4));

        $issue = StockMovement::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('movement_type', MovementType::Issue)
            ->sole();

        $this->assertCount(2, BatchMovement::query()->where('movement_id', $issue->id)->get());
    }

    /**
     * 🚨 Gate r4 R4-1 — the discriminating test the legless fixture could not be.
     *
     * `outstandingShippedLots()` used to take the ceiling as the NET of every leg
     * a lot had ever had, and kept only lots whose net was negative. A lot that
     * arrived through a goods receipt carries a POSITIVE leg for its whole
     * received quantity, so its net can never go below zero — a lot cannot ship
     * more than it received. Every ledger-recorded lot was therefore permanently
     * invisible to the restore arm, and every customer return minted a fresh
     * `DEFAULT` lot ranked `today + 365`: the exact phantom this lane exists to
     * eliminate, re-minted on every return, with short-dated goods re-labelled as
     * untracked stock and the recall trail pointing at the wrong lot.
     *
     * The gate's PG probe, verbatim: a GRN-sourced lot of 18 ships 5 (→ 13), then
     * 5 come back. The lot must return to 18 and NO `DEFAULT` lot may exist.
     */
    public function test_a_return_of_grn_sourced_stock_credits_the_origin_lot_and_mints_no_default(): void
    {
        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        // Precondition that makes this test discriminating: the lots carry their
        // inbound legs, exactly like the wave-4 tenant's real lots.
        $this->assertSame(
            2,
            BatchMovement::query()->where('quantity', '>', 0)->count(),
            'fixture precondition: both lots were seeded through a receipt leg',
        );

        $this->issueFromLots('5.0000');

        $this->assertSame(0, bccomp('13.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4));

        $this->confirmedReturnNote('5.0000');

        $this->assertSame(
            0,
            bccomp('18.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4),
            'the GRN-sourced origin lot must be credited back — the gate measured it stuck at 13',
        );
        $this->assertSame(0, bccomp('12.0000', (string) BatchStock::where('batch_id', $late->id)->value('quantity'), 4));

        $this->assertNull(
            Batch::query()
                ->where('product_id', $this->dpProduct->id)
                ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                ->first(),
            'the gate measured a DEFAULT lot minted at 5.0000 with expiry today+365 — it must not exist',
        );

        $this->assertSame(0, bccomp('30.0000', $this->totalBatchQuantity(), 4));
    }

    /**
     * Gate r4 R4-1, anti-double-credit on the OUTSTANDING-outbound ceiling: a
     * second return with no matching shipment cannot credit the origin lot twice.
     * The first return already consumed the lot's outstanding outbound, so the
     * surplus lands on the `DEFAULT` lot and `Σ lots` still reconciles.
     */
    public function test_a_second_return_cannot_credit_the_origin_lot_twice(): void
    {
        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $this->issueFromLots('5.0000');
        $this->confirmedReturnNote('5.0000');

        $this->assertSame(0, bccomp('18.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4));

        // Nothing else was shipped, so this one has no origin to go back to.
        $this->confirmedReturnNote('5.0000');

        $this->assertSame(
            0,
            bccomp('18.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4),
            'the lot is not credited past what it actually shipped',
        );

        $default = Batch::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();

        $this->assertNotNull($default);
        if ($default === null) {
            return;
        }

        $this->assertSame(0, bccomp('5.0000', (string) BatchStock::where('batch_id', $default->id)->value('quantity'), 4));
        $this->assertSame(
            0,
            bccomp((string) StockLevel::query()->where('product_id', $this->dpProduct->id)->value('quantity'),
                $this->totalBatchQuantity(), 4),
            'Σ lots still reconciles with the aggregate',
        );
    }

    /**
     * Gate r4 R4-8 — "most-recently-shipped first" must be measured over OUTBOUND
     * legs only. Netting the max over every leg lets a lot that RECEIVED stock
     * yesterday sort ahead of one that SHIPPED today.
     *
     * Both lots ship; the later-shipping lot must be credited first.
     */
    public function test_the_restore_order_is_by_most_recent_shipment_not_most_recent_leg(): void
    {
        $first = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '10.0000');
        $second = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '10.0000');

        // Ship 10 from the earliest-expiry lot, then 5 from the later one. The
        // LATER shipment is the more recent one.
        $this->issueFromLots('10.0000');
        $this->travelTo(now()->addMinutes(5));
        $this->issueFromLots('5.0000');

        $this->assertSame(0, bccomp('0.0000', (string) BatchStock::where('batch_id', $first->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('5.0000', (string) BatchStock::where('batch_id', $second->id)->value('quantity'), 4));

        $this->travelTo(now()->addMinutes(5));
        $this->confirmedReturnNote('5.0000');

        $this->assertSame(
            0,
            bccomp('10.0000', (string) BatchStock::where('batch_id', $second->id)->value('quantity'), 4),
            'the most recently SHIPPED lot is credited first',
        );
        $this->assertSame(0, bccomp('0.0000', (string) BatchStock::where('batch_id', $first->id)->value('quantity'), 4));
    }

    /**
     * 🚨 Gate r5 R5-1, document channel — a return sourced from a delivery credits
     * the lots THAT DELIVERY drew, not whatever the tuple shipped most recently.
     *
     * Two dated lots; delivery 1 draws the short-dated lot A (FEFO), delivery 2
     * draws the long-dated lot B. Returning against delivery 1 must credit lot A.
     * The tuple-wide heuristic would credit B, because B shipped last.
     */
    public function test_a_document_return_credits_the_lots_its_own_source_delivery_drew(): void
    {
        $this->skipUnlessPostgres();

        $lotA = $this->seedLot('LOT-A-SHORT', now()->addDays(30)->toDateString(), '5.0000');
        $lotB = $this->seedLot('LOT-B-LONG', now()->addDays(150)->toDateString(), '10.0000');

        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('product_id', $this->dpProduct->id)
            ->update(['quantity' => '15.0000']);

        $firstDelivery = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);
        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);

        $this->assertSame(0, bccomp('0.0000', (string) BatchStock::where('batch_id', $lotA->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('5.0000', (string) BatchStock::where('batch_id', $lotB->id)->value('quantity'), 4));

        $this->confirmedReturnNote('5.0000', $firstDelivery);

        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $lotA->id)->value('quantity'), 4),
            'the lot the SOURCE delivery drew is credited, not the most-recently-shipped one',
        );
        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $lotB->id)->value('quantity'), 4),
        );
    }

    /**
     * 🚨 Gate r5 R5-2 — a batch-tracked product with an ACTIVE VARIANT could not be
     * returned at all.
     *
     * The outbound arm passes `$line->variant_id`; the restore arm did not, so on a
     * variant-bearing product it looked for PRODUCT-LEVEL lots, found none, fell
     * through to the `DEFAULT` arm — and that arm's variant guard (added in r4,
     * correctly) THREW `MissingVariantException` inside the confirm transaction. A
     * guard meant to stop a forbidden mint was firing on an ordinary return, and
     * the operator could not process it at all. In a parapharmacy, where every
     * product is batch-tracked, that is one catalogue edit away.
     *
     * Everything here is variant-scoped, which is what the guard demands: the lot,
     * the stock row, the shipment and the return line.
     */
    public function test_a_return_on_a_variant_scoped_line_credits_the_variant_lot_instead_of_throwing(): void
    {
        $variant = ProductVariant::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'variant_code' => 'V-200ML',
            'sku' => 'W27-VAR-200',
            'name_suffix' => '200ml',
        ]);

        $batchService = app(BatchStockService::class);

        $lot = $batchService->findOrCreateBatch(
            companyId: $this->dpCompany->id,
            tenantId: $this->dpTenant->id,
            productId: $this->dpProduct->id,
            batchNumber: 'LOT-VAR-2026A',
            expiryDate: now()->addDays(30)->toDateString(),
            manufacturingDate: now()->subDay()->toDateString(),
            variantId: $variant->id,
        );

        $receipt = StockMovement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'variant_id' => $variant->id,
            'location_id' => $this->dpLocation->id,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => '30.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '30.0000',
            'reference' => 'GRN-VAR',
            'occurred_at' => now()->subDay(),
        ]);

        $batchService->receiveBatchStock(
            tenantId: $this->dpTenant->id,
            batchId: $lot->id,
            locationId: $this->dpLocation->id,
            quantity: '30.0000',
            movementId: $receipt->id,
        );

        StockLevel::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'variant_id' => $variant->id,
            'location_id' => $this->dpLocation->id,
            'quantity' => '30.0000',
            'reserved' => '0.0000',
        ]);

        // Ship 5 of the variant's lot.
        $issue = StockMovement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'variant_id' => $variant->id,
            'location_id' => $this->dpLocation->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::Delivery,
            'quantity' => '5.0000',
            'quantity_before' => '30.0000',
            'quantity_after' => '25.0000',
            'reference' => 'DN-VAR',
            'occurred_at' => now(),
        ]);

        $batchService->issueBatchStock(
            tenantId: $this->dpTenant->id,
            batchId: $lot->id,
            locationId: $this->dpLocation->id,
            quantity: '5.0000',
            movementId: $issue->id,
        );

        $this->assertSame(0, bccomp('25.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4));

        $this->confirmedReturnNote('5.0000', null, $variant->id);

        $this->assertSame(
            0,
            bccomp('30.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4),
            'the variant lot is credited back — before r5 this threw MissingVariantException',
        );
        $this->assertSame(
            0,
            Batch::query()
                ->where('product_id', $this->dpProduct->id)
                ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                ->count(),
        );
    }

    /**
     * 🚨 Gate r3 R3-1 — the INBOUND mirror. W4-5 taught the delivery arm to
     * consume lots; `ReturnNoteService::receiveStockBack()` credited only the
     * aggregate, so a sale followed by a return left `Σ lots` BELOW
     * `stock_levels` — the opposite drift, and a HARD one:
     *
     *   after DN(5):     aggregate 25.0000   Σ lots 25.0000
     *   after RETURN(5): aggregate 30.0000   Σ lots 25.0000   <-- lots understate
     *   deliver 30:      InsufficientBatchStockException, shortfall 5.0000 (422)
     *
     * while the stock screen shows the goods on hand. It also made
     * `untrackedRemainderAt()` report 5.0000 where the true remainder is zero, so
     * the next sibling seam would re-mint a `DEFAULT` lot for goods that came back
     * from a short-dated lot — a product-safety defect in a parapharmacy, not just
     * a ledger one.
     *
     * The gate's exact probe: sell 5, return 5, then deliver all 30.
     */
    public function test_a_returned_quantity_goes_back_on_the_lot_it_left_and_the_next_delivery_succeeds(): void
    {
        $this->skipUnlessPostgres();

        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);

        $this->assertSame(0, bccomp('25.0000', $this->totalBatchQuantity(), 4));
        $this->assertSame(0, bccomp('13.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4));

        $this->confirmedReturnNote('5.0000');

        // The units go back on the lot they LEFT, not onto a new DEFAULT lot.
        $this->assertSame(
            0,
            bccomp('18.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4),
            'the 5 units shipped from the early lot are credited back to it',
        );
        $this->assertSame(0, bccomp('12.0000', (string) BatchStock::where('batch_id', $late->id)->value('quantity'), 4));

        $this->assertSame(
            0,
            bccomp('30.0000', $this->totalBatchQuantity(), 4),
            'Σ lots must equal stock_levels again — the gate measured 25 against 30.',
        );
        $this->assertSame(
            0,
            bccomp('30.0000', (string) StockLevel::query()
                ->where('product_id', $this->dpProduct->id)
                ->value('quantity'), 4),
        );

        $this->assertNull(
            Batch::query()
                ->where('product_id', $this->dpProduct->id)
                ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
                ->first(),
            'a resolvable origin lot must never be re-labelled as untracked stock',
        );

        // The whole 30 is deliverable again — the gate measured a 422 here.
        $second = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('30.0000')]);
        $this->assertSame(DocumentStatus::Confirmed, $second->refresh()->status);
        $this->assertSame(0, bccomp('0.0000', $this->totalBatchQuantity(), 4));
    }

    /**
     * Gate r3 R3-1, the credit is DOCUMENT-PER-ACTION: one
     * `inventory_batch_movements` leg per lot, keyed to the return's own receipt
     * movement — the same shape the outbound arm writes.
     */
    public function test_the_returned_quantity_carries_its_own_batch_movement_leg(): void
    {
        // Gate r4 R4-5: runs on BOTH drivers. The inbound arm needs no PG-only
        // syntax, and the outbound leg is seeded directly rather than through a
        // delivery-note confirm (which does need `FOR UPDATE … SKIP LOCKED`).
        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $this->issueFromLots('5.0000');
        $this->confirmedReturnNote('5.0000');

        $creditMovement = StockMovement::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('reason', MovementReason::CustomerReturn)
            ->sole();

        $legs = BatchMovement::query()->where('movement_id', $creditMovement->id)->get();
        $this->assertCount(1, $legs);
        $this->assertSame($early->id, (int) $legs->first()?->batch_id);
        $this->assertSame(0, bccomp('5.0000', (string) $legs->first()?->quantity, 4));
    }

    /**
     * Gate r3 R3-1, the unresolvable case. A return with no shipment history for
     * the tuple (goods the ERP never saw leave a lot) cannot be credited to an
     * origin lot. It is credited to the `DEFAULT` lot **with its own leg**, so
     * `Σ lots` still reconciles and the units are VISIBLY untracked rather than
     * silently missing — the reviewer's "minimum viable" arm.
     */
    public function test_a_return_with_no_resolvable_origin_lot_lands_on_the_default_lot_with_a_leg(): void
    {
        // Gate r4 R4-5: no delivery note is confirmed here, so the PG-only skip
        // this test used to carry never applied to it.
        $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '30.0000');

        $this->confirmedReturnNote('4.0000');

        $default = Batch::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();

        $this->assertNotNull($default, 'unattributable returned stock must be visible, not missing');
        if ($default === null) {
            return;
        }

        $this->assertSame(0, bccomp('4.0000', (string) BatchStock::where('batch_id', $default->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('34.0000', $this->totalBatchQuantity(), 4));

        $creditMovement = StockMovement::query()
            ->where('product_id', $this->dpProduct->id)
            ->where('reason', MovementReason::CustomerReturn)
            ->sole();
        $this->assertCount(1, BatchMovement::query()->where('movement_id', $creditMovement->id)->get());
    }

    // ───────── gate r3 R3-4 / R3-6 — the refusals this lane introduced ─────────

    /**
     * Gate r3 R3-6 — the single most valuable property of the W4-5 change, and
     * nothing pinned it: a delivery the lots cannot cover is refused ATOMICALLY.
     * `issueStock()` loops the lines and `recordSale()` has already moved the
     * aggregate by the time the lot draw fails, so "refused" has to mean the
     * whole confirm rolls back — not a half-issued delivery.
     */
    public function test_a_delivery_the_lots_cannot_cover_is_refused_atomically(): void
    {
        $this->skipUnlessPostgres();

        // 10 in lots against an aggregate of 30 — the drifted shape a
        // pre-W4-5 tenant carries.
        $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '10.0000');

        // The fixture's own goods-receipt movements exist; what must not change is
        // the count AFTER the refused confirm.
        $movementsBefore = StockMovement::query()->count();
        $legsBefore = BatchMovement::query()->count();

        $this->expectException(InsufficientBatchStockException::class);

        try {
            $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('20.0000')]);
        } finally {
            $this->assertSame(
                0,
                bccomp('30.0000', (string) StockLevel::query()
                    ->where('product_id', $this->dpProduct->id)
                    ->value('quantity'), 4),
                'the aggregate must be untouched — recordSale() ran before the lot draw failed',
            );
            $this->assertSame($movementsBefore, StockMovement::query()->count(), 'no NEW movement may survive the rollback');
            $this->assertSame($legsBefore, BatchMovement::query()->count());
            $this->assertSame(0, bccomp('10.0000', $this->totalBatchQuantity(), 4));
            $this->assertSame(
                DocumentStatus::Draft,
                Document::query()->where('type', DocumentType::DeliveryNote)->sole()->status,
            );
        }
    }

    /**
     * Gate r3 R3-6, multi-line: line 1 is fulfillable and line 2 is short. Line
     * 1's `recordSale()` movement AND its lot draw are already written when line
     * 2 fails, so BOTH must roll back.
     */
    public function test_a_short_second_line_rolls_back_the_first_lines_issue_and_lot_draw(): void
    {
        $this->skipUnlessPostgres();

        $lot = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '6.0000');

        $movementsBefore = StockMovement::query()->count();
        $legsBefore = BatchMovement::query()->count();

        $this->expectException(InsufficientBatchStockException::class);

        try {
            $this->dpConfirmedDeliveryNote([
                $this->dpPhysicalLine('5.0000'),
                $this->dpPhysicalLine('5.0000'),
            ]);
        } finally {
            $this->assertSame($movementsBefore, StockMovement::query()->count());
            $this->assertSame($legsBefore, BatchMovement::query()->count());
            $this->assertSame(
                0,
                bccomp('6.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4),
                'line 1 drew 5 from this lot before line 2 failed; the rollback must undo it',
            );
            $this->assertSame(
                0,
                bccomp('30.0000', (string) StockLevel::query()
                    ->where('product_id', $this->dpProduct->id)
                    ->value('quantity'), 4),
            );
        }
    }

    /**
     * Gate r3 R3-4 (a) — EXPIRED lots are invisible to FEFO
     * (`FEFOInventoryService::suggestBatchesForSale()` /
     * `consumeBatchesAtomically()` both filter `expiry_date >= today`), so stock
     * sitting entirely in an expired lot is now UNDELIVERABLE.
     *
     * That is the right answer for a parapharmacy — do not ship expired goods —
     * but it is a policy being enforced by a `WHERE` clause, and it used to ship.
     * Pinned so the behaviour is deliberate and visible, and stated in
     * `DeliveryNoteService::issueStock()`'s comment block.
     */
    public function test_stock_held_only_in_an_expired_lot_is_refused(): void
    {
        $this->skipUnlessPostgres();

        $expired = $this->seedLot('LOT-CRÈME-2025Z', now()->subDays(3)->toDateString(), '30.0000');
        $movementsBefore = StockMovement::query()->count();

        $this->expectException(InsufficientBatchStockException::class);

        try {
            $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);
        } finally {
            $this->assertSame(
                0,
                bccomp('30.0000', (string) BatchStock::where('batch_id', $expired->id)->value('quantity'), 4),
                'the expired lot is not drawn from',
            );
            $this->assertSame($movementsBefore, StockMovement::query()->count());
        }
    }

    /**
     * Gate r3 R3-4 (b) — a batch-tracked tuple with aggregate stock and NO lots
     * at all is refused. `GoodsReceiptService` forces batch data on the common
     * inbound path, so this is the shape left by a path that never minted a lot.
     */
    public function test_a_batch_tracked_tuple_with_no_lots_at_all_is_refused(): void
    {
        $this->skipUnlessPostgres();

        $movementsBefore = StockMovement::query()->count();

        $this->expectException(InsufficientBatchStockException::class);

        try {
            $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);
        } finally {
            $this->assertSame($movementsBefore, StockMovement::query()->count());
            $this->assertSame(
                DocumentStatus::Draft,
                Document::query()->where('type', DocumentType::DeliveryNote)->sole()->status,
            );
        }
    }

    /**
     * Gate r3 R3-4 (c) — FEFO order is availability-ordered, not expiry-ordered,
     * once a lot carries a hold: `available_quantity` is a GENERATED
     * `quantity − reserved_quantity`, so a fully-held earliest lot is skipped and
     * the LATER lot ships. Correct (a hold is stock promised to someone else),
     * but it means the shipped lot is decided partly by hold state, which the
     * docblock's bare "FEFO" does not say.
     */
    public function test_a_held_earliest_lot_is_skipped_and_the_later_lot_ships(): void
    {
        $this->skipUnlessPostgres();

        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $late = $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        BatchStock::where('batch_id', $early->id)->update(['reserved_quantity' => '18.0000']);

        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);

        $this->assertSame(
            0,
            bccomp('18.0000', (string) BatchStock::where('batch_id', $early->id)->value('quantity'), 4),
            'the fully-held earliest lot is untouched',
        );
        $this->assertSame(
            0,
            bccomp('7.0000', (string) BatchStock::where('batch_id', $late->id)->value('quantity'), 4),
            'the later lot ships instead',
        );
    }

    /**
     * Gate r1 finding 7, REWRITTEN after wave-4 W4-5.
     *
     * The r1 version of this test pinned the GAP: it asserted that DN confirm
     * released the hold and left both lots untouched, and its docblock recorded
     * that as a named follow-up. W4-5 made that follow-up urgent and the two tests
     * above now fix it, so the old assertions would pin the defect. What survives
     * — and still matters — is the RESERVATION half: the hold is released
     * WHOLESALE by source (`DeliveryNoteService::releaseReservationsForSource()`),
     * and the reservation's `batch_id` is never propagated onto the delivery-note
     * line. So the FEFO lot chosen at CONFIRM time is a soft hold; which lot is
     * actually issued is decided independently, at DN confirm, by
     * `consumeBatchesAtomically()`.
     *
     * The two can disagree — a lot that was reserved may not be the lot that
     * ships if stock moved in between — and propagating the reservation's lot to
     * the DN line is still a named follow-up lane. It is no longer a
     * *ledger-integrity* problem, only a *which-lot-was-promised* one.
     */
    public function test_delivery_note_confirm_releases_the_hold_wholesale_without_propagating_its_lot(): void
    {
        $this->skipUnlessPostgres();

        $early = $this->seedLot('LOT-CRÈME-2026A', now()->addDays(30)->toDateString(), '18.0000');
        $this->seedLot('LOT-CRÈME-2026B', now()->addDays(150)->toDateString(), '12.0000');

        $order = $this->draftSalesOrderFor('5.0000');
        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertStatus(200);

        $reservation = StockReservation::query()->where('source_id', $order->id)->sole();
        $this->assertSame($early->id, $reservation->batch_id);

        $deliveryNote = $this->dpConfirmedDeliveryNote(
            [$this->dpPhysicalLine('5.0000')],
            ['source_document_id' => $order->id],
        );

        $this->assertSame(DocumentStatus::Confirmed, $deliveryNote->refresh()->status);

        $this->assertNotNull(
            StockReservation::query()->where('source_id', $order->id)->sole()->released_at,
            'the hold is released wholesale by source',
        );

        $this->assertNull(
            $deliveryNote->lines->first()?->batch_id,
            'the reservation lot is NOT propagated onto the delivery-note line — a named follow-up lane',
        );

        // The ledger is nonetheless consistent, because DN confirm consumes FEFO
        // itself (the W4-5 fix above).
        $this->assertSame(0, bccomp('25.0000', $this->totalBatchQuantity(), 4));
    }

    /**
     * Ship `$quantity` out of the tuple's lots, FEFO-first, the way a confirmed
     * delivery does: one `issue` stock movement plus one NEGATIVE
     * `inventory_batch_movements` leg per lot drawn, and the aggregate reduced.
     *
     * Written with `BatchStockService::issueBatchStock()` rather than
     * `consumeBatchesAtomically()` on purpose (gate r4 R4-5): the atomic consume
     * needs `FOR UPDATE … SKIP LOCKED`, which SQLite cannot parse, and the INBOUND
     * arm under test here needs no PG-only syntax at all. Seeding the outbound leg
     * directly is what lets the restore tests run on the DEFAULT driver.
     *
     * @param  numeric-string  $quantity
     */
    private function issueFromLots(string $quantity): StockMovement
    {
        $stockLevel = StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('product_id', $this->dpProduct->id)
            ->firstOrFail();

        /** @var numeric-string $before */
        $before = (string) $stockLevel->quantity;
        /** @var numeric-string $after */
        $after = bcsub($before, $quantity, 4);

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::Delivery,
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reference' => 'DN-SEED',
            'occurred_at' => now(),
        ]);

        $stockLevel->update(['quantity' => $after]);

        /** @var numeric-string $remaining */
        $remaining = $quantity;

        $lots = BatchStock::query()
            ->select('inventory_batch_stock.*')
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $this->dpProduct->id)
            ->where('product_batches.batch_number', '!=', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->where('inventory_batch_stock.location_id', $this->dpLocation->id)
            ->orderBy('product_batches.expiry_date')
            ->get();

        foreach ($lots as $lot) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            /** @var numeric-string $available */
            $available = (string) $lot->quantity;
            /** @var numeric-string $take */
            $take = bccomp($available, $remaining, 4) < 0 ? $available : $remaining;

            if (bccomp($take, '0', 4) <= 0) {
                continue;
            }

            app(BatchStockService::class)->issueBatchStock(
                tenantId: $this->dpTenant->id,
                batchId: (int) $lot->batch_id,
                locationId: $this->dpLocation->id,
                quantity: $take,
                movementId: $movement->id,
            );

            $remaining = bcsub($remaining, $take, 4);
        }

        return $movement;
    }

    /**
     * A DRAFT return note for the fixture product, confirmed through
     * `ReturnNoteService` so the real inbound stock path runs.
     *
     * @param  numeric-string  $quantity
     */
    private function confirmedReturnNote(string $quantity, ?Document $source = null, ?string $variantId = null): Document
    {
        $returnNote = Document::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'W27-RN-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'location_id' => $this->dpLocation->id,
            'source_document_id' => $source?->id,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::ReturnNote),
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'variant_id' => $variantId,
            'location_id' => $this->dpLocation->id,
            'description' => 'returned goods',
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '100.000',
        ]);

        return app(ReturnNoteService::class)->confirm($returnNote->refresh());
    }

    /**
     * `FEFOInventoryService::consumeBatchesAtomically()` uses
     * `FOR UPDATE … SKIP LOCKED`, which SQLite does not parse — the same reason
     * `BatchChainE2ETest` is PostgreSQL-only. Lot consumption is therefore
     * exercised on the PostgreSQL leg (`autoerp_test_w27`, port 5433).
     */
    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Requires PostgreSQL: FEFO lot consumption uses FOR UPDATE SKIP LOCKED.');
        }
    }
}
