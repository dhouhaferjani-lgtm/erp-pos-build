<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T7 (plan CF §3) — `GET /invoices/{id}/can-cancel` carries the modal's inputs. [PG]
 *
 * CF-D5 and CF-D6 make this endpoint load-bearing for the modal's CONTENT, not merely
 * for whether the Cancel button is live: which options render, which are disabled and
 * why, whether someone already decided, and how a multi-location delivery will split.
 *
 * The read model has to AGREE with the write path or the UI lies — the same property
 * `cancellationBlockReason()` already exists to guarantee for the button. So the
 * fail-closed cases below matter most: an invoice with no delivery linkage must report
 * `goods_issued: false`, because posting an invoice moves no stock and a
 * catalogue-shaped answer here would offer a one-click restock of goods that never
 * shipped.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class CanCancelReturnDecisionReadModelTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private string $issuedOn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-can-cancel');
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
        $this->issuedOn = Carbon::today()->subDays(5)->toDateString();
    }

    public function test_the_pre_existing_keys_are_unchanged(): void
    {
        $invoice = $this->physicalInvoice('4.0000');

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.reason_code', null)
            ->assertJsonPath('data.status', DocumentStatus::Posted->value);
    }

    /**
     * Services only: the option group is omitted and the modal posts
     * `not_applicable`. The modal itself still renders — `reason` is
     * `required|max:500` and has no other UI.
     */
    public function test_a_services_only_invoice_requires_no_return_decision(): void
    {
        $invoice = $this->cfPostedInvoice([$this->cfServiceLine()], ['document_date' => $this->issuedOn]);

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', false)
            ->assertJsonPath('data.goods_issued', false)
            ->assertJsonPath('data.delivered_quantities', [])
            ->assertJsonPath('data.return_decision', null);
    }

    /**
     * The CF-D6 middle branch: physical lines, but nothing ever shipped. Options 1 and
     * 2 render DISABLED with a translated reason, and option 3 posts `no_goods_issued`
     * rather than `no_return`.
     */
    public function test_an_invoice_with_no_delivery_linkage_reports_goods_issued_false(): void
    {
        $invoice = $this->physicalInvoice('4.0000');

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', true)
            ->assertJsonPath('data.goods_issued', false)
            ->assertJsonPath('data.delivered_quantities', []);
    }

    public function test_a_dn_sourced_invoice_reports_goods_issued_with_per_product_quantities(): void
    {
        $invoice = $this->physicalInvoice('5.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('4.0000', $this->cfLocationA->id)]);

        $response = $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', true)
            ->assertJsonPath('data.goods_issued', true);

        $quantities = $response->json('data.delivered_quantities');
        self::assertCount(1, $quantities);
        self::assertSame($this->cfProduct->id, $quantities[0]['product_id']);
        self::assertSame($this->cfLocationA->id, $quantities[0]['location_id']);
        self::assertSame('4.0000', $quantities[0]['delivered']);
        self::assertSame('4.0000', $quantities[0]['remaining']);
    }

    /**
     * The SO-driven linkage (fiscal gate N-I2). Without both traversals this reports
     * `goods_issued: false` for genuinely delivered goods, permanently disabling
     * options 1 and 2 — fail-closed, so no phantom stock, but silently unusable.
     */
    public function test_an_so_sourced_invoice_also_reports_goods_issued(): void
    {
        $invoice = $this->physicalInvoice('5.0000');
        $this->cfLinkInvoiceViaSalesOrder($invoice, [$this->dn('3.0000', $this->cfLocationA->id)]);

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.goods_issued', true)
            ->assertJsonPath('data.delivered_quantities.0.delivered', '3.0000');
    }

    /**
     * The split the modal has to be able to explain: one product, two locations, two
     * rows.
     */
    public function test_a_multi_location_delivery_is_reported_per_location(): void
    {
        $invoice = $this->physicalInvoice('5.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $quantities = $this->canCancel($invoice)->assertOk()->json('data.delivered_quantities');

        self::assertCount(2, $quantities);
        $byLocation = array_column($quantities, 'remaining', 'location_id');
        self::assertSame('3.0000', $byLocation[$this->cfLocationA->id]);
        self::assertSame('2.0000', $byLocation[$this->cfLocationB->id]);
    }

    /**
     * After a full return there is nothing left to bring back, so options 1 and 2 must
     * not be offered — `goods_issued` is "any tuple REMAINING > 0", not "delivered > 0".
     */
    public function test_a_fully_returned_invoice_reports_zero_remaining_and_goods_issued_false(): void
    {
        $invoice = $this->physicalInvoice('4.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('4.0000', $this->cfLocationA->id)]);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => [
                    'mode' => ReturnDecisionMode::AlreadyReturned->value,
                    'returned_on' => Carbon::today()->toDateString(),
                ],
            ])->assertOk();

        $response = $this->canCancel($invoice->refresh())->assertOk();

        self::assertSame('0.0000', $response->json('data.delivered_quantities.0.remaining'));
        self::assertSame('4.0000', $response->json('data.delivered_quantities.0.already_returned'));
        self::assertFalse($response->json('data.goods_issued'));
    }

    /**
     * CF-D5's readback. Without it the modal would offer a choice that is going to 422,
     * and the owner's "the choice is recorded" would exist only in the database.
     */
    public function test_a_recorded_decision_is_read_back(): void
    {
        $invoice = $this->physicalInvoice('4.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('4.0000', $this->cfLocationA->id)]);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])->assertOk();

        $response = $this->canCancel($invoice->refresh())->assertOk();

        self::assertSame(ReturnDecisionMode::WillReturn->value, $response->json('data.return_decision.mode'));
        self::assertTrue($response->json('data.return_decision.accepted'));
        self::assertSame($this->cfUser->id, $response->json('data.return_decision.decided_by'));
        self::assertNotNull($response->json('data.return_decision.return_note_id'));
    }

    /**
     * A REJECTED decision is an audit record, not state — the read model must not
     * report it as the decision that took effect.
     */
    public function test_a_rejected_decision_is_not_read_back_as_the_recorded_one(): void
    {
        $invoice = $this->physicalInvoice('4.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('4.0000', $this->cfLocationA->id)]);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])->assertOk();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Changed my mind',
                'return_decision' => ['mode' => ReturnDecisionMode::NoReturn->value],
            ])->assertStatus(422);

        $response = $this->canCancel($invoice->refresh())->assertOk();

        self::assertSame(
            ReturnDecisionMode::WillReturn->value,
            $response->json('data.return_decision.mode'),
            'The ACCEPTED decision is the state; the rejection is only an audit record.',
        );
    }

    /**
     * Fix round 1 · inv gate F-1 — the read model and the restock predicate must
     * answer the SAME question.
     *
     * T4 replaced `receiveStockBack()`'s guard with `PhysicalLinePredicate`, which
     * excludes a NON-PHYSICAL PRODUCT line (`product_id` set, `is_physical = false`).
     * `requiresReturnDecision()` kept keying on `product_id !== null`, and its
     * docblock still claimed the two were identical. They were not, and the gap is
     * operator-facing, not cosmetic: for an invoice whose only lines are
     * non-physical products the modal demands a goods disposition, `not_applicable`
     * is REFUSED by `assertDecisionMatchesGoods()`, and any goods-bearing mode
     * builds a return-note line that `receiveStockBack()` then silently skips.
     */
    public function test_a_non_physical_product_line_requires_no_return_decision(): void
    {
        $invoice = $this->nonPhysicalProductInvoice('2.0000');

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', false)
            ->assertJsonPath('data.goods_issued', false);
    }

    public function test_a_non_physical_product_invoice_can_be_cancelled_as_not_applicable(): void
    {
        $invoice = $this->nonPhysicalProductInvoice('2.0000');

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Duplicate billing',
                'return_decision' => ['mode' => ReturnDecisionMode::NotApplicable->value],
            ])->assertOk();

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
    }

    /**
     * Fix round 2 · fiscal gate NEW-1 (P1, introduced by fix round 1's F-1).
     *
     * F-1 moved `requiresReturnDecision()` and the RN-line builder onto
     * `PhysicalLinePredicate` but left the THIRD participant —
     * `DeliveredQuantityResolver::resolve()` (`:79-82`) — keying `product_id !== null`.
     * A non-physical PRODUCT line delivered on a confirmed delivery note therefore
     * produced a tuple, so `hasGoodsIssued()` said TRUE while
     * `requiresReturnDecision()` said FALSE: the modal reports goods are out AND
     * omits the option group, and the guided cancel accepts `not_applicable` for an
     * invoice the read model has just said carries goods.
     *
     * The delivery note moves no stock for that line either (T4 made
     * `DeliveryNoteService::issueStock()` skip it), so there is nothing out there.
     * The resolver has to agree.
     */
    public function test_a_delivered_non_physical_product_line_reports_no_goods_issued(): void
    {
        $product = $this->nonPhysicalProduct();
        $invoice = $this->invoiceFor($product, '2.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dnFor($product, '2.0000', $this->cfLocationA->id)]);

        $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', false)
            ->assertJsonPath('data.goods_issued', false)
            ->assertJsonPath('data.delivered_quantities', []);
    }

    /**
     * …and the consequence the incoherence carried: a goods-bearing decision
     * completed with an EMPTY return note.
     *
     * `createReturnNoteForDecision()` gates on the resolver's tuples (`$live`), then
     * prices them through `returnNoteLinesFor()`, which since F-1 filters on the
     * predicate. A phantom tuple survives the gate and prices to nothing —
     * `if ($productLines === []) continue` — so `createDraft(lines: [])` builds a
     * return note with no lines and the cancel returns 200. Nothing is credited,
     * nothing is restocked, and no exception is raised.
     *
     * Once the resolver agrees there is nothing out there, the typed
     * `RETURN_NOTHING_DELIVERED` refusal fires instead, which is CF-D6's enforcement
     * half doing its job.
     */
    public function test_a_delivered_non_physical_product_line_cannot_produce_an_empty_return_note(): void
    {
        $product = $this->nonPhysicalProduct();
        $invoice = $this->invoiceFor($product, '2.0000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dnFor($product, '2.0000', $this->cfLocationA->id)]);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_NOTHING_DELIVERED');

        self::assertSame(
            0,
            Document::query()
                ->where('company_id', $this->cfCompany->id)
                ->where('type', DocumentType::ReturnNote)
                ->count(),
            'an empty return note must never be created — it credits nothing and restocks nothing',
        );
    }

    /**
     * The MIXED invoice, which is where the money actually goes wrong: one physical
     * line and one non-physical line, both on the same delivery note.
     *
     * The read model must offer exactly the physical tuple, and the return note must
     * carry exactly the physical line — priced, so the customer IS credited for it.
     * Before the fix the resolver reported two tuples and the modal offered a
     * disposition for goods that never moved.
     */
    public function test_a_mixed_invoice_offers_only_the_physical_tuple(): void
    {
        $nonPhysical = $this->nonPhysicalProduct();

        $invoice = $this->cfPostedInvoice([
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '3.0000',
                'unit_price' => '100.000',
            ],
            [
                'product_id' => $nonPhysical->id,
                'quantity' => '2.0000',
                'unit_price' => '100.000',
            ],
        ], ['document_date' => $this->issuedOn]);

        $deliveryNote = $this->cfConfirmedDeliveryNote([
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '3.0000',
                'unit_price' => '100.000',
            ],
            [
                'product_id' => $nonPhysical->id,
                'quantity' => '2.0000',
                'unit_price' => '100.000',
            ],
        ], [
            'location_id' => $this->cfLocationA->id,
            'document_date' => $this->issuedOn,
            'fiscal_status' => FiscalStatus::Sealed,
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$deliveryNote]);

        $quantities = $this->canCancel($invoice)
            ->assertOk()
            ->assertJsonPath('data.requires_return_decision', true)
            ->assertJsonPath('data.goods_issued', true)
            ->json('data.delivered_quantities');

        self::assertCount(1, $quantities, 'only the physical product left the building');
        self::assertSame($this->cfProduct->id, $quantities[0]['product_id']);
        self::assertSame('3.0000', $quantities[0]['remaining']);

        // …and `not_applicable` is refused, because this invoice DOES carry goods.
        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::NotApplicable->value],
            ])->assertStatus(422);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])->assertOk();

        /** @var Document $returnNote */
        $returnNote = Document::query()
            ->where('company_id', $this->cfCompany->id)
            ->where('type', DocumentType::ReturnNote)
            ->with('lines')
            ->sole();

        self::assertCount(1, $returnNote->lines, 'exactly the physical line, priced');

        $returnLine = $returnNote->lines->first();
        self::assertNotNull($returnLine);
        self::assertSame($this->cfProduct->id, $returnLine->product_id);
        self::assertSame('3.0000', (string) $returnLine->quantity);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return TestResponse<JsonResponse>
     */
    private function canCancel(Document $invoice): TestResponse
    {
        return $this->actingAs($this->cfUser, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel");
    }

    private function physicalInvoice(string $quantity): Document
    {
        return $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);
    }

    /**
     * A PRODUCT that does not move stock (`is_physical = false`) — the population
     * D-19 / T4 turned on.
     */
    private function nonPhysicalProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'name' => 'CF Non-Physical Product',
            'sku' => 'CF-NONPHYS-'.bin2hex(random_bytes(3)),
            'type' => ProductType::Service,
            'unit' => 'hour',
            'cost_price' => '10.000000',
            'is_active' => true,
            'is_physical' => false,
        ]);
    }

    private function nonPhysicalProductInvoice(string $quantity): Document
    {
        return $this->invoiceFor($this->nonPhysicalProduct(), $quantity);
    }

    private function invoiceFor(Product $product, string $quantity): Document
    {
        return $this->cfPostedInvoice([[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);
    }

    private function dnFor(Product $product, string $quantity, string $locationId): Document
    {
        return $this->cfConfirmedDeliveryNote([[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], [
            'location_id' => $locationId,
            'document_date' => $this->issuedOn,
            'fiscal_status' => FiscalStatus::Sealed,
        ]);
    }

    private function dn(string $quantity, string $locationId): Document
    {
        return $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], [
            'location_id' => $locationId,
            'document_date' => $this->issuedOn,
            'fiscal_status' => FiscalStatus::Sealed,
        ]);
    }
}
