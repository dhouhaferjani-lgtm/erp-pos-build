<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Document\Domain\Exceptions\ReturnQuantityExceededException;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * Gate CF fix round 1 — the two fiscal Criticals. [PG]
 *
 * Both are defects in the INPUTS the gate-ruled mechanisms are fed, not in the
 * mechanisms themselves, and both produce a silent, unrefused, wrong fiscal outcome on
 * a SEALED document:
 *
 * 1. **Which invoice line prices a tuple.** `returnNoteLinesFor()` priced the whole
 *    delivered quantity of a product from the FIRST invoice line carrying it, while the
 *    resolver aggregates delivered quantity across ALL of that product's lines. Two
 *    lines for one product is an ordinary invoice — `CreateDocumentRequest` places no
 *    `distinct` rule on `lines.*.product_id`, and CF-D11 point 2 relies on that fact for
 *    the tuple split itself. So the sealed return note could carry money the sale never
 *    had, and `prorateFlatDiscount()` could divide by a quantity that is not the
 *    quantity being split — producing a NEGATIVE flat discount on a sealed line.
 *
 * 2. **Which prior return notes count.** Both nets filtered `source_document_id ==
 *    invoice`, so a return note raised against the DELIVERY NOTE — the second entry
 *    point, which 422'd on every submit before this lane and which T8 repaired — was
 *    invisible. The same units restocked twice.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class GuidedCancelFlowMultiLineAndNettingTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private string $issuedOn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-multiline');
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
        $this->issuedOn = Carbon::today()->subDays(5)->toDateString();
    }

    // ── CRITICAL 1: a product on two invoice lines ────────────────────────────

    /**
     * The gate's first probe. Product P on two lines — 3 @ 100.000 with a flat discount
     * of 3.000, and 2 @ 50.000 — delivered 5 from one location.
     *
     * Pricing all 5 from the first line yielded a sealed RN net of 497.000 against a
     * sale net of 397.000: the return overstates the sale by 100.000 (25 %), on a
     * document whose `total` is a hash input and whose VAT base derives from it.
     */
    public function test_a_product_on_two_invoice_lines_is_priced_from_each_of_its_own_lines(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '3.0000', 'unit_price' => '100.000', 'discount_amount' => '3.000'],
            ['quantity' => '2.0000', 'unit_price' => '50.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('5.0000', $this->cfLocationA->id)]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);

        // One RN line per (source line, location): the 3 priced at 100.000 carrying the
        // 3.000 discount, and the 2 priced at 50.000 carrying none.
        self::assertCount(2, $returnNote->lines);

        /** @var array<string, DocumentLine> $byPrice */
        $byPrice = $returnNote->lines
            ->keyBy(static fn (DocumentLine $line): string => (string) $line->unit_price)
            ->all();
        self::assertSame('3.0000', (string) $byPrice['100.000']->quantity);
        self::assertSame('3.000', (string) $byPrice['100.000']->discount_amount);
        self::assertSame('2.0000', (string) $byPrice['50.000']->quantity);

        // The sealed return net now equals the sale net for the returned quantity:
        // (3×100 − 3) + (2×50) = 297.000 + 100.000 = 397.000.
        self::assertSame('397.000', (string) $returnNote->subtotal);
        self::assertSame((string) $invoice->subtotal, (string) $returnNote->subtotal);
    }

    /**
     * The gate's second, sharper probe of the same root cause: when the first line's
     * quantity is SMALLER than a later tuple's, `qtyRatio` exceeded 1 and the residue
     * sink went NEGATIVE — a flat discount that *increases* the line net, persisted on a
     * sealed fiscal line. The sink was doing its job; its input was wrong.
     */
    public function test_no_split_line_carries_a_negative_flat_discount(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '1.0000', 'unit_price' => '100.000', 'discount_amount' => '5.000'],
            ['quantity' => '4.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);

        foreach ($returnNote->lines as $line) {
            $discount = (string) ($line->discount_amount ?? '0');
            self::assertGreaterThanOrEqual(
                0,
                bccomp($discount, '0', 3),
                "A sealed fiscal line must never carry a negative flat discount; got {$discount}.",
            );
            // And no line may be discounted below zero net either.
            self::assertGreaterThanOrEqual(0, bccomp((string) $line->line_total, '0', 3));
        }

        // Σ of the split discounts still equals the source line's, exactly.
        $sum = '0.000';
        foreach ($returnNote->lines as $line) {
            $sum = bcadd($sum, (string) ($line->discount_amount ?? '0'), 3);
        }
        self::assertSame('5.000', $sum);

        // Sale net = (1×100 − 5) + (4×100) = 495.000; the full return matches it.
        self::assertSame('495.000', (string) $returnNote->subtotal);
    }

    /**
     * A product on two lines whose delivered quantity spans two locations: the split has
     * to be by (source line, location), so the allocation crosses both dimensions.
     */
    public function test_the_split_is_keyed_by_source_line_and_location_together(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '3.0000', 'unit_price' => '100.000'],
            ['quantity' => '3.0000', 'unit_price' => '80.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('4.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);

        // 3 @100 and 3 @80 invoiced; 4 @ L1 and 2 @ L2 delivered. Allocation in
        // line_number order across the tuples in (product, location) order:
        //   L1 4 → line1 3, line2 1;  L2 2 → line2 2.
        self::assertCount(3, $returnNote->lines);
        // 3×100 + 1×80 + 2×80 = 540.000 — which is exactly the sale net (3×100 + 3×80),
        // as it must be for a full return. Under the old product-keyed pricing all six
        // units would have been priced at the FIRST line's 100.000 ⇒ 600.000.
        self::assertSame('540.000', (string) $returnNote->subtotal);
        self::assertSame((string) $invoice->subtotal, (string) $returnNote->subtotal);

        $atA = $returnNote->lines->where('location_id', $this->cfLocationA->id);
        $atB = $returnNote->lines->where('location_id', $this->cfLocationB->id);
        self::assertSame('4.0000', $this->sumQuantity($atA->all()));
        self::assertSame('2.0000', $this->sumQuantity($atB->all()));

        // Per-location stock deltas still land correctly.
        self::assertSame('4.0000', $this->stockAt($this->cfLocationA->id));
        self::assertSame('2.0000', $this->stockAt($this->cfLocationB->id));
    }

    /**
     * A partial return of a discounted line prorates the discount to the quantity
     * ACTUALLY returned — not the whole line discount onto a smaller quantity.
     */
    public function test_a_partial_return_prorates_the_flat_discount_to_the_returned_quantity(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000', 'discount_amount' => '5.000'],
        ]);
        // Only 2 of the 5 were ever delivered.
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('2.0000', $this->cfLocationA->id)]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertCount(1, $returnNote->lines);

        // 2/5 of 5.000 = 2.000 — NOT the whole 5.000 on a qty-2 line.
        self::assertSame('2.000', (string) $returnNote->lines->first()?->discount_amount);
        self::assertSame('198.000', (string) $returnNote->subtotal);
    }

    /**
     * Over-return still reaches the cap. The allocator must not silently drop the
     * excess, or the refusal it is supposed to trigger never fires.
     */
    public function test_delivered_more_than_invoiced_still_hits_the_over_return_cap(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '2.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('5.0000', $this->cfLocationA->id)]);

        $this->cancelAlreadyReturned($invoice)
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnQuantityExceededException::CODE_INVOICED);

        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
        self::assertNull($this->returnNoteFor($invoice));
    }

    // ── CRITICAL 2: prior returns raised against the delivery note ────────────

    /**
     * The gate's phantom-inventory probe. 5 delivered, returned once through the
     * DELIVERY-NOTE entry point (the surface T8 repaired, so this is live in the same
     * merge), then the guided cancel: previously 200 OK with stock at 10.0000 — five
     * units delivered, returned once, and the warehouse holding ten.
     */
    public function test_a_delivery_note_sourced_prior_return_is_netted_against_delivered(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        // A return note raised against the DELIVERY NOTE, confirmed — stock comes back.
        $this->confirmDeliveryNoteSourcedReturn($dn, '5.0000');
        self::assertSame('5.0000', $this->stockAt($this->cfLocationA->id));

        $this->cancelAlreadyReturned($invoice)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RETURN_NOTHING_DELIVERED');

        // The decisive assertion: no phantom inventory.
        self::assertSame(
            '5.0000',
            $this->stockAt($this->cfLocationA->id),
            'Five units delivered and returned once must never leave ten in the warehouse.',
        );
        self::assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
    }

    /**
     * The partial shape: 5 delivered, 2 already returned via the delivery note, so only
     * 3 may come back.
     */
    public function test_a_partial_delivery_note_sourced_return_reduces_what_remains(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $this->confirmDeliveryNoteSourcedReturn($dn, '2.0000');
        self::assertSame('2.0000', $this->stockAt($this->cfLocationA->id));

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertSame('3.0000', (string) $returnNote->lines->first()?->quantity);

        // 2 already back + 3 now = the 5 that left. Not 7.
        self::assertSame('5.0000', $this->stockAt($this->cfLocationA->id));
    }

    /**
     * The read model has to agree with the write path, or the modal offers a choice the
     * server will refuse.
     */
    public function test_can_cancel_reports_the_delivery_note_sourced_return_as_already_returned(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);
        $this->confirmDeliveryNoteSourcedReturn($dn, '5.0000');

        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoice->id}/can-cancel")
            ->assertOk();

        self::assertFalse($response->json('data.goods_issued'));
        self::assertSame('5.0000', $response->json('data.delivered_quantities.0.already_returned'));
        self::assertSame('0.0000', $response->json('data.delivered_quantities.0.remaining'));
    }

    /**
     * The standalone entry point is capped over the widened set too — otherwise a user
     * could return 5 against the delivery note and 5 more against the invoice by hand.
     */
    public function test_the_standalone_route_also_nets_delivery_note_sourced_returns(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);
        $this->confirmDeliveryNoteSourcedReturn($dn, '5.0000');

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $invoice->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '5.0000',
                    'unit_price' => '100.000',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnQuantityExceededException::CODE_INVOICED);
    }

    // ── the MIRROR netting direction (gate CF round 2) ───────────────────────

    /**
     * The reviewer's three-step sequence, and the decisive one: it STARTS WITH THIS
     * LANE'S OWN COMPOSITE.
     *
     * `will_return` leaves a DRAFT return note against the INVOICE. The DN-side cap only
     * widened invoice → delivery notes, so from the delivery note it never looked at that
     * draft: the DN-sourced create succeeded, both notes confirmed, and five delivered
     * units became ten in stock.
     *
     * Round 1 closed one order of operations and left the other open, which reads as
     * fixed and is not — the worse of the two states.
     */
    public function test_a_dn_sourced_return_sees_the_guided_will_return_draft(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        // Step 1 — this lane's guided cancel leaves a DRAFT return note on the invoice.
        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled the order',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])
            ->assertOk();

        self::assertSame('0.0000', $this->stockAt($this->cfLocationA->id), 'A draft moves no stock yet.');

        // Step 2 — the SAME five units, claimed again from the delivery-note surface.
        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $dn->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '5.0000',
                    'unit_price' => '100.000',
                    'location_id' => $this->cfLocationA->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnQuantityExceededException::CODE_INVOICED);

        // Step 3 — confirming the guided draft restocks exactly the five that left.
        $draft = $this->returnNoteFor($invoice);
        self::assertNotNull($draft);
        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/return-notes/{$draft->id}/confirm")
            ->assertOk();

        self::assertSame(
            '5.0000',
            $this->stockAt($this->cfLocationA->id),
            'Five units delivered and claimed twice must never leave ten in the warehouse.',
        );
    }

    /**
     * The same mirror with a CONFIRMED invoice-sourced return as step 1 — the reviewer's
     * first reproduction.
     */
    public function test_a_dn_sourced_return_sees_a_confirmed_invoice_sourced_return(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $this->cancelAlreadyReturned($invoice)->assertOk();
        self::assertSame('5.0000', $this->stockAt($this->cfLocationA->id));

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $dn->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '5.0000',
                    'unit_price' => '100.000',
                    'location_id' => $this->cfLocationA->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnQuantityExceededException::CODE_INVOICED);

        self::assertSame('5.0000', $this->stockAt($this->cfLocationA->id));
    }

    /**
     * The sales-order linkage shape: the invoice carries `source_document_id = order`, so
     * the reverse traversal cannot find it by `payload->source_delivery_note_ids` and has
     * to go through the delivery note's own order.
     */
    public function test_the_mirror_also_resolves_through_the_sales_order_shape(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);
        $invoice = $this->invoiceWithLines([
            ['quantity' => '5.0000', 'unit_price' => '100.000'],
        ]);
        $order = $this->cfLinkInvoiceViaSalesOrder($invoice, [$dn]);
        // The delivery note hangs off the same order — the shape SalesOrderToInvoiceConverter produces.
        $dn->update(['source_document_id' => $order->id]);

        $this->cancelAlreadyReturned($invoice->refresh())->assertOk();

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $dn->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '5.0000',
                    'unit_price' => '100.000',
                    'location_id' => $this->cfLocationA->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ReturnQuantityExceededException::CODE_INVOICED);
    }

    /**
     * The bound: an UNINVOICED delivery note has no invoice to net against, so the
     * DN-only net is already complete and the reverse traversal must not refuse it.
     */
    public function test_an_uninvoiced_delivery_note_return_is_still_permitted(): void
    {
        $dn = $this->dn('5.0000', $this->cfLocationA->id);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $dn->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '5.0000',
                    'unit_price' => '100.000',
                    'location_id' => $this->cfLocationA->id,
                ]],
            ])
            ->assertCreated();
    }

    // ── NEW-1: the capacity ledger must net prior returns ────────────────────

    /**
     * C1's defect class surviving through the capacity ledger.
     *
     * The ledger was seeded from the GROSS invoiced quantity and never reduced by prior
     * returns, so after a partial return the surviving units were re-priced from lines
     * that had already been consumed. Reviewer's probe: sale net 400.000, returned value
     * 500.000 sealed, no refusal — because `assertWithinReturnableQuantities()` nets per
     * PRODUCT (3 + 2 ≤ 5 passes) and cannot see which LINE the units came from.
     */
    public function test_the_capacity_ledger_nets_prior_returns_before_pricing(): void
    {
        $invoice = $this->invoiceWithLines([
            ['quantity' => '3.0000', 'unit_price' => '100.000'],
            ['quantity' => '2.0000', 'unit_price' => '50.000'],
        ]);
        self::assertSame('400.000', (string) $invoice->subtotal);

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('5.0000', $this->cfLocationA->id)]);

        // A prior confirmed return of 3 units — which consumed the whole first line.
        $prior = $this->confirmInvoiceSourcedReturn($invoice, '3.0000', '100.000');
        self::assertSame('300.000', (string) $prior->subtotal);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $guided = Document::query()
            ->where('type', DocumentType::ReturnNote)
            ->where('source_document_id', $invoice->id)
            ->where('id', '!=', $prior->id)
            ->with('lines')
            ->first();
        self::assertNotNull($guided);

        // The only units left on the invoice are the 2 @ 50.000 — so that is what the
        // surviving return must be priced at.
        self::assertSame('100.000', (string) $guided->subtotal);
        self::assertSame('50.000', (string) $guided->lines->first()?->unit_price);

        // And the total returned value never exceeds what was sold.
        /** @var numeric-string $returnedValue */
        $returnedValue = bcadd((string) $prior->subtotal, (string) $guided->subtotal, 3);
        /** @var numeric-string $invoiceNet */
        $invoiceNet = (string) $invoice->subtotal;
        self::assertSame('400.000', $returnedValue);
        self::assertLessThanOrEqual(0, bccomp($returnedValue, $invoiceNet, 3));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return TestResponse<JsonResponse>
     */
    private function cancelAlreadyReturned(Document $invoice): TestResponse
    {
        return $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled the order',
                'return_decision' => [
                    'mode' => ReturnDecisionMode::AlreadyReturned->value,
                    'returned_on' => Carbon::today()->toDateString(),
                ],
            ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function invoiceWithLines(array $lines): Document
    {
        return $this->cfPostedInvoice(
            array_map(fn (array $line): array => array_merge([
                'product_id' => $this->cfProduct->id,
                'tax_rate' => '0.00',
            ], $line), $lines),
            ['document_date' => $this->issuedOn],
        );
    }

    private function dn(string $quantity, string $locationId): Document
    {
        return $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => '100.000',
        ]], ['location_id' => $locationId, 'document_date' => $this->issuedOn]);
    }

    /**
     * A CONFIRMED return note raised against the DELIVERY NOTE — the second entry point,
     * whose payload carries `source_document_id: <delivery note>`.
     */
    private function confirmDeliveryNoteSourcedReturn(Document $deliveryNote, string $quantity): Document
    {
        /** @var numeric-string $quantity */
        $rn = Document::create([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'location_id' => $this->cfLocationA->id,
            'partner_id' => $this->cfPartner->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CF-RN-DN-'.bin2hex(random_bytes(4)),
            'document_date' => Carbon::today()->toDateString(),
            'source_document_id' => $deliveryNote->id,
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $rn->id,
            'line_number' => 1,
            'product_id' => $this->cfProduct->id,
            'location_id' => $this->cfLocationA->id,
            'description' => 'CF Physical Product',
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => bcmul($quantity, '100.000', 3),
        ]);

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/return-notes/{$rn->id}/confirm")
            ->assertOk();

        return $rn->refresh();
    }

    /**
     * A CONFIRMED return note raised against the INVOICE, through the real routes.
     */
    private function confirmInvoiceSourcedReturn(Document $invoice, string $quantity, string $unitPrice): Document
    {
        $created = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => Carbon::today()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $invoice->id,
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'location_id' => $this->cfLocationA->id,
                ]],
            ])
            ->assertCreated();

        $id = (string) $created->json('data.id');

        $this->actingAs($this->cfUser, 'sanctum')
            ->postJson("/api/v1/return-notes/{$id}/confirm")
            ->assertOk();

        /** @var Document $note */
        $note = Document::query()->with('lines')->findOrFail($id);

        return $note;
    }

    private function returnNoteFor(Document $invoice): ?Document
    {
        return Document::query()
            ->where('type', DocumentType::ReturnNote)
            ->where('source_document_id', $invoice->id)
            ->with('lines')
            ->first();
    }

    private function stockAt(string $locationId): string
    {
        $level = StockLevel::query()
            ->where('product_id', $this->cfProduct->id)
            ->where('location_id', $locationId)
            ->first();

        return $level === null ? '0.0000' : (string) $level->quantity;
    }

    /**
     * @param  array<int, DocumentLine>  $lines
     */
    private function sumQuantity(array $lines): string
    {
        $sum = '0.0000';
        foreach ($lines as $line) {
            $sum = bcadd($sum, (string) $line->quantity, 4);
        }

        return $sum;
    }
}
