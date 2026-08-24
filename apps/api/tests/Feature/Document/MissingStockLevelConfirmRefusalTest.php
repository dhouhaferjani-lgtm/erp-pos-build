<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * Campaign defect N-2 — day-one products have no `stock_levels` row at all.
 *
 * Both write lanes used to reach `StockLevel::…->firstOrFail()`, so the absent
 * row surfaced as `ModelNotFoundException`:
 *   - reservation lane  `StockReservationService::reserve()` → raw **404** out of
 *     `SalesOrderController::confirm()` (its only catch is `\DomainException`);
 *   - WAC sale lane     `WeightedAverageCostService::recordSale()` → reached from
 *     delivery-note confirm and the two invoice convenience endpoints.
 *
 * Gate r1 M-1 — the two WAC callers did NOT answer alike on base, and the tests
 * below encode the difference: the two invoice convenience endpoints returned a
 * raw **404**, while delivery-note confirm returned a 422 with the misleading code
 * `CONFIGURATION_ERROR`, because that controller's pre-existing
 * `catch (\RuntimeException)` arm already swallowed `ModelNotFoundException`
 * (which IS a `\RuntimeException`). Sales-order confirm had a third answer again:
 * 404 for the absent row, and **500** for an existing row at quantity 0.
 *
 * The policy for an EXISTING row at quantity 0 is a REFUSAL (see
 * `StockReservationService` `bccomp($available, $quantity) < 0` and
 * `WeightedAverageCostService`'s negative-residual guard), so an ABSENT row —
 * available 0 by definition — must refuse identically: a typed
 * `INSUFFICIENT_STOCK` 422, never a 404 and never a phantom stock row.
 */
class MissingStockLevelConfirmRefusalTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    /**
     * The day-one shape: the product exists, the location exists, and no
     * `stock_levels` row was ever written for the tuple.
     */
    private function removeStockLevelRows(): void
    {
        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->delete();

        $this->assertSame(
            0,
            StockLevel::query()->where('company_id', $this->dpCompany->id)->count(),
            'Fixture precondition: the tuple must have NO stock_levels row.',
        );
    }

    /**
     * A DRAFT sales order with one physical, located line — the shape
     * `SalesOrderService::confirm()` reserves stock for.
     */
    private function draftSalesOrder(): Document
    {
        $order = Document::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'DP-SO-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'location_id' => $this->dpLocation->id,
            'currency' => 'TND',
            'subtotal' => '200.000',
            'tax_amount' => '0.000',
            'total' => '200.000',
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::SalesOrder),
        ]);

        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => '3E physical line',
            'quantity' => '2.0000',
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '200.000',
        ]);

        return $order->refresh();
    }

    // ───────────────────────── reservation lane ──────────────────────────

    public function test_sales_order_confirm_refuses_with_typed_422_when_stock_level_row_is_absent(): void
    {
        $this->removeStockLevelRows();
        $order = $this->draftSalesOrder();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertStringContainsString('3E Physical Product', (string) $response->json('error.message'));

        $this->assertSame(DocumentStatus::Draft, $order->refresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(
            0,
            StockLevel::query()->where('company_id', $this->dpCompany->id)->count(),
            'A refused reservation must not materialise a phantom stock_levels row.',
        );
    }

    /**
     * Parity pin: an EXISTING row at quantity 0 is the policy this lane copies.
     * Both shapes must produce the SAME refusal, with the same machine code.
     */
    public function test_sales_order_confirm_refuses_identically_when_the_row_exists_at_zero(): void
    {
        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->update(['quantity' => '0.0000', 'reserved' => '0.0000']);

        $order = $this->draftSalesOrder();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(DocumentStatus::Draft, $order->refresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
    }

    // ───────────────────────────── WAC lane ──────────────────────────────

    public function test_delivery_note_confirm_refuses_with_typed_422_when_stock_level_row_is_absent(): void
    {
        $this->removeStockLevelRows();
        $deliveryNote = $this->dpDraftDeliveryNote([$this->dpPhysicalLine('2.0000')]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/delivery-notes/{$deliveryNote->id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $deliveryNote->refresh();
        $this->assertSame(DocumentStatus::Draft, $deliveryNote->status);
        $this->assertNull($deliveryNote->fiscal_hash);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(
            0,
            StockLevel::query()->where('company_id', $this->dpCompany->id)->count(),
            'A refused issue must not materialise a phantom stock_levels row.',
        );
    }

    public function test_create_delivery_and_post_refuses_with_typed_422_and_leaves_the_invoice_untouched(): void
    {
        $this->removeStockLevelRows();
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);
        $payloadBefore = $invoice->payload;

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Confirmed, $invoice->status);
        $this->assertNull($invoice->fiscal_hash);
        $this->assertSame($payloadBefore, $invoice->payload);
        $this->assertSame(
            0,
            Document::query()->where('type', DocumentType::DeliveryNote)->count(),
            'The whole guided transaction must roll back — no delivery note survives.',
        );
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_confirm_deliveries_and_post_refuses_with_typed_422_when_stock_level_row_is_absent(): void
    {
        $this->removeStockLevelRows();

        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);
        $deliveryNote = $this->dpDraftDeliveryNote([$this->dpPhysicalLine('2.0000')]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/confirm-deliveries-and-post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
        $this->assertSame(DocumentStatus::Draft, $deliveryNote->refresh()->status);
        $this->assertSame(0, StockMovement::query()->count());
    }

    // ──────────────── batch-tracked day-one shape (gate r1 I-1) ─────────────────

    /**
     * Flip the fixture product to the parapharmacy default. `requires_batch_tracking`
     * is what routes `reserve()` through
     * `StockReservationService::resolveDefaultBatchIdForImplicitReservation()`, the
     * branch this lane changed from `firstOrFail()` to `first()` + `return null` —
     * a branch NO other test in this class executes.
     */
    private function makeProductBatchTracked(): void
    {
        $this->dpProduct->update(['requires_batch_tracking' => true]);
        $this->assertTrue($this->dpProduct->refresh()->requires_batch_tracking);
    }

    public function test_batch_tracked_sales_order_confirm_refuses_with_typed_422_when_stock_level_row_is_absent(): void
    {
        $this->makeProductBatchTracked();
        $this->removeStockLevelRows();
        $order = $this->draftSalesOrder();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(DocumentStatus::Draft, $order->refresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(
            0,
            BatchStock::query()->count(),
            'A day-one batch-tracked refusal must not mint a default lot: with no stock '
            .'row there is no quantity to seed one from, and a zero lot would route the '
            .'next attempt into the batch branch\'s own firstOrFail() — the N-2 defect again.',
        );
        $this->assertSame(
            0,
            StockLevel::query()->where('company_id', $this->dpCompany->id)->count(),
        );
    }

    /**
     * Parity pin for the batch-tracked lane: an EXISTING aggregate row at 0 must
     * give the same refusal as no row at all. This one DOES reach
     * `ensureDefaultBatch()` (the stock row exists), which must decline to mint a
     * lot for a non-positive target.
     */
    public function test_batch_tracked_sales_order_confirm_refuses_identically_when_the_row_exists_at_zero(): void
    {
        $this->makeProductBatchTracked();
        StockLevel::query()
            ->where('company_id', $this->dpCompany->id)
            ->update(['quantity' => '0.0000', 'reserved' => '0.0000']);

        $order = $this->draftSalesOrder();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(DocumentStatus::Draft, $order->refresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, BatchStock::query()->count());
    }

    // ───────────── structural pin: read, never create (gate r1 M-2) ──────────────

    /**
     * Gate r1 M-2 — a DB-level "no phantom row" assertion cannot discriminate
     * `first()` from `firstOrCreate()`: the refusal throws out of `reserve()`'s own
     * `DB::transaction`, so anything written inside is rolled back either way. The
     * reviewer proved it by mutating the service to create a zero row and watching
     * the row-count assertions stay green.
     *
     * The decision is therefore pinned STRUCTURALLY, the same way
     * `tests/Architecture/InventoryCostLockCoverageTest::test_lock_free_methods_operate_only_on_existing_rows`
     * pins `recordSale`'s `firstOrFail`: the aggregate lookup in `reserve()` must
     * READ, never create.
     */
    public function test_the_reservation_lane_reads_the_aggregate_row_and_never_creates_it(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Modules/Inventory/Application/Services/StockReservationService.php'),
        );

        // Matched as a CALL (`->firstOrCreate(`), so the prose in this file's own
        // comments explaining why it is not used cannot satisfy the assertion.
        $this->assertStringNotContainsString(
            '->firstOrCreate(',
            $source,
            'StockReservationService must never firstOrCreate a stock_levels row. An absent '
            .'row is available 0 and is REFUSED; materialising a row for a refused write '
            .'leaves litter that the very next predicate rejects anyway. StockAdjustmentService '
            .'uses firstOrCreate because it is the lane that legitimately BRINGS stock in.',
        );

        $this->assertStringNotContainsString(
            '->firstOrFail(',
            $this->extractMethodBody($source, 'private function resolveDefaultBatchIdForImplicitReservation'),
            'The implicit-batch pre-read runs BEFORE the aggregate branch, so a firstOrFail() '
            .'here re-opens N-2 on every batch-tracked day-one product.',
        );
    }

    /**
     * Crude but sufficient: take everything from the signature to the next
     * declaration at the same indentation.
     */
    private function extractMethodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "Method not found: {$signature}");

        $rest = substr($source, (int) $start);
        $end = strpos($rest, "\n    }\n");

        return $end === false ? $rest : substr($rest, 0, $end);
    }
}
