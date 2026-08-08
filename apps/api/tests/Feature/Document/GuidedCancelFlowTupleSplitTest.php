<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T5 (plan CF §3) — the `(product, location)` tuple split and its money. [PG]
 *
 * Two independent failure modes live here, and they need DIFFERENT KINDS of
 * assertion — that distinction is the point of the class:
 *
 * 1. **Wrong location.** A product delivered 3 from L1 and 2 from L2 must restock 3
 *    to L1 and 2 to L2. One return-note line for the product would restock 5 into one
 *    place: two units created where they never left, silently, one click, with no
 *    refusal — because a location DOES resolve, it is just wrong for part of the
 *    quantity.
 *
 * 2. **Wrong money.** `DocumentLine::computeLineTotal()` subtracts a flat
 *    `discount_amount` WHOLE and then floors the line at zero, so copying it verbatim
 *    onto both split lines subtracts it TWICE — understating the sealed net and its
 *    VAT base on a document whose `total` is a hash input.
 *
 *    The discount test is an **EQUALITY** assertion against the SOURCE value, not a
 *    characterization pin: a pin would happily lock in the doubled-discount bug,
 *    because it records whatever the implementation produces. The TOTAL, by contrast,
 *    gets a **BOUND plus a pinned value** — bare equality against an unsplit note
 *    would be legitimately flaky, since splitting adds a `bcmul` truncation point per
 *    extra line.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class GuidedCancelFlowTupleSplitTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private string $issuedOn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-tuple-split');
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
        $this->issuedOn = Carbon::today()->subDays(5)->toDateString();
    }

    // ── 1. the location split ─────────────────────────────────────────────────

    /**
     * "3 from L1 and 2 from L2 restocks 3 to L1 and 2 to L2" — across TWO delivery
     * notes (the multi-DN consolidation shape).
     */
    public function test_two_delivery_notes_from_two_locations_restock_to_both(): void
    {
        $invoice = $this->invoiceWithLine('5.0000', '100.000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertCount(2, $returnNote->lines, 'One return-note line per (product, location) tuple.');

        // `receiveStockBack()` iterates strictly per line, so two lines is the only
        // way both locations get their units back.
        self::assertSame('3.0000', $this->stockAt($this->cfLocationA->id));
        self::assertSame('2.0000', $this->stockAt($this->cfLocationB->id));
        self::assertSame(2, StockMovement::query()->count(), 'Two tuples, two recordReturn() calls.');
    }

    /**
     * The same split WITHIN one delivery note, via per-line `location_id` — the shape
     * `DocumentLine::getEffectiveLocationId()`'s docblock exists for and `issueStock()`
     * honours per line.
     */
    public function test_per_line_locations_inside_one_delivery_note_restock_to_both(): void
    {
        $invoice = $this->invoiceWithLine('5.0000', '100.000');

        $dn = $this->cfConfirmedDeliveryNote([
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '3.0000',
                'unit_price' => '100.000',
                'location_id' => $this->cfLocationA->id,
            ],
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '2.0000',
                'unit_price' => '100.000',
                'location_id' => $this->cfLocationB->id,
            ],
        ], ['location_id' => $this->cfLocationA->id, 'document_date' => $this->issuedOn]);

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        self::assertSame('3.0000', $this->stockAt($this->cfLocationA->id));
        self::assertSame('2.0000', $this->stockAt($this->cfLocationB->id));
    }

    /**
     * CF-D11's central guarantee: the restock location comes from the DELIVERY NOTE,
     * even when neither the invoice nor its lines carry one. Invoices move no stock,
     * so nothing requires them to have a location — and inventing one is the defect.
     */
    public function test_an_invoice_with_no_location_at_all_still_restocks_to_the_delivery_notes(): void
    {
        $invoice = $this->invoiceWithLine('4.0000', '100.000');
        self::assertNull($invoice->location_id, 'The fixture must really have no invoice location.');

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('4.0000', $this->cfLocationB->id)]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertNull($returnNote->location_id, 'No document-level fallback is written.');
        self::assertSame(
            $this->cfLocationB->id,
            $returnNote->lines->first()?->location_id,
            'The location is written EXPLICITLY onto the line, so getEffectiveLocationId() never falls through.',
        );
        self::assertSame('4.0000', $this->stockAt($this->cfLocationB->id));
        self::assertSame('0.0000', $this->stockAt($this->cfLocationA->id));
    }

    public function test_partially_delivered_lines_are_capped_and_undelivered_tuples_are_dropped(): void
    {
        // Invoiced 5, delivered only 2.
        $invoice = $this->invoiceWithLine('5.0000', '100.000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('2.0000', $this->cfLocationA->id)]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $returnNote = $this->returnNoteFor($invoice);
        self::assertNotNull($returnNote);
        self::assertCount(1, $returnNote->lines);
        self::assertSame('2.0000', (string) $returnNote->lines->first()?->quantity);
        self::assertSame('2.0000', $this->stockAt($this->cfLocationA->id));
    }

    // ── 2. the money ──────────────────────────────────────────────────────────

    /**
     * EQUALITY, deliberately. `Σ` of the split lines' `discount_amount` must equal the
     * SOURCE line's exactly. 5.000 over 3 + 2 of 5 does divide evenly; the 1 + 2 of 3
     * case below does not, and is what actually exercises the residue sink.
     */
    public function test_a_flat_discount_is_prorated_and_sums_exactly_to_the_source(): void
    {
        $invoice = $this->invoiceWithLine('5.0000', '100.000', discountAmount: '5.000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $lines = $this->returnNoteFor($invoice)?->lines;
        self::assertNotNull($lines);
        self::assertCount(2, $lines);

        self::assertSame('5.000', $this->sumDiscount($lines->all()));
    }

    /**
     * The non-evenly-dividing case: 5.000 over 1 + 2 of 3. `bcmul` truncates, so the
     * prorated parts alone would NOT sum to the source — the first tuple's residue
     * sink is what makes the equality hold by construction.
     */
    public function test_the_residue_sink_makes_an_uneven_split_sum_exactly(): void
    {
        $invoice = $this->invoiceWithLine('3.0000', '100.000', discountAmount: '5.000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('1.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $lines = $this->returnNoteFor($invoice)?->lines;
        self::assertNotNull($lines);
        self::assertCount(2, $lines);
        self::assertSame('5.000', $this->sumDiscount($lines->all()));

        // 2 of 3 ⇒ ratio 0.6666 ⇒ 5.000 × 0.6666 = 3.333 (truncated); the first tuple
        // takes 5.000 − 3.333 = 1.667. Pinned so a change to the sink is visible.
        $byLocation = $this->discountByLocation($lines->all());
        self::assertSame('1.667', $byLocation[$this->cfLocationA->id]);
        self::assertSame('3.333', $byLocation[$this->cfLocationB->id]);
    }

    /**
     * An UNSPLIT return of the same goods carries the discount verbatim — the residue
     * sink degenerates to `source − 0`, so there is no special case for one tuple.
     */
    public function test_an_unsplit_return_carries_the_flat_discount_verbatim(): void
    {
        $invoice = $this->invoiceWithLine('5.0000', '100.000', discountAmount: '5.000');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$this->dn('5.0000', $this->cfLocationA->id)]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $lines = $this->returnNoteFor($invoice)?->lines;
        self::assertNotNull($lines);
        self::assertCount(1, $lines);
        self::assertSame('5.000', (string) $lines->first()?->discount_amount);
    }

    /**
     * BOUND + PIN for the sealed total. Splitting adds one `bcmul` truncation point
     * per extra line, so the split note's total may differ from the unsplit one by at
     * most one currency-scale unit per extra line. Bare equality would be a
     * legitimately flaky assertion; the pin catches any change larger than the bound.
     */
    public function test_the_split_total_stays_within_one_scale_unit_per_extra_line(): void
    {
        $split = $this->invoiceWithLine('5.0000', '100.000', discountAmount: '5.000', taxRate: '19.00');
        $this->cfLinkInvoiceToDeliveryNotes($split, [
            $this->dn('3.0000', $this->cfLocationA->id, '100.000'),
            $this->dn('2.0000', $this->cfLocationB->id, '100.000'),
        ]);
        $this->cancelAlreadyReturned($split)->assertOk();
        $splitNote = $this->returnNoteFor($split);
        self::assertNotNull($splitNote);

        // Net: (3×100 − 1.667) + (2×100 − 3.333) = 298.333 + 196.667 = 495.000 — the
        // residue sink is what makes the two nets sum to the unsplit net exactly.
        // VAT then lands on the ideal 94.050 (495.000 × 19%), because
        // TaxCalculationService groups lines into a rate BUCKET before applying the
        // rate rather than truncating each line's tax independently. So this
        // particular split costs nothing at all.
        //
        // The PIN below is what makes that visible; the BOUND below it is what stays
        // true when a different quantity/price combination does land on a truncation
        // boundary. Do not replace the bound with equality — that would be a
        // legitimately flaky assertion.
        self::assertSame('495.000', (string) $splitNote->subtotal);
        self::assertSame('94.050', (string) $splitNote->tax_amount);
        self::assertSame('589.050', (string) $splitNote->total);

        // The unsplit equivalent: 5×100 − 5.000 = 495.000, VAT 94.050.
        $unsplitTotal = '589.050';
        $delta = bcsub((string) $splitNote->total, $unsplitTotal, 3);
        $delta = bccomp($delta, '0', 3) < 0 ? bcmul($delta, '-1', 3) : $delta;

        // One extra line ⇒ at most one currency-scale unit (0.001 at TND scale 3).
        self::assertLessThanOrEqual(0, bccomp($delta, '0.001', 3));
    }

    /**
     * `discount_percent` is proportional by construction, so it is copied VERBATIM —
     * and a percent-plus-flat line still prorates the flat amount even though percent
     * dominates `computeLineTotal()`'s precedence, so the stored line data stays
     * honest rather than carrying a misleading flat amount on every split line.
     */
    public function test_discount_percent_is_verbatim_while_a_coexisting_flat_amount_is_still_prorated(): void
    {
        $invoice = $this->invoiceWithLine(
            '5.0000',
            '100.000',
            discountAmount: '5.000',
            discountPercent: '10.00',
        );
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id),
            $this->dn('2.0000', $this->cfLocationB->id),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $lines = $this->returnNoteFor($invoice)?->lines;
        self::assertNotNull($lines);
        foreach ($lines as $line) {
            self::assertSame('10.00', (string) $line->discount_percent, 'A percentage applies unchanged to any sub-quantity.');
        }
        self::assertSame('5.000', $this->sumDiscount($lines->all()));

        // Percent takes precedence in the totals: (3×100)−10% + (2×100)−10% = 450.000.
        self::assertSame('450.000', (string) $this->returnNoteFor($invoice)?->subtotal);
    }

    /**
     * `unit_price` and `tax_rate` are VERBATIM — never recomputed, never re-derived
     * from a proportion. That is what keeps the return note mirroring the sale's VAT
     * by construction (CF-D2): line tax comes from the line's own stored rate, not
     * from the effective `TaxConfiguration`.
     */
    public function test_unit_price_and_tax_rate_are_copied_verbatim_onto_every_split_line(): void
    {
        $invoice = $this->invoiceWithLine('5.0000', '133.333', taxRate: '7.00');
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [
            $this->dn('3.0000', $this->cfLocationA->id, '133.333'),
            $this->dn('2.0000', $this->cfLocationB->id, '133.333'),
        ]);

        $this->cancelAlreadyReturned($invoice)->assertOk();

        $lines = $this->returnNoteFor($invoice)?->lines;
        self::assertNotNull($lines);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertSame('133.333', (string) $line->unit_price);
            self::assertSame('7.00', (string) $line->tax_rate);
        }
    }

    /**
     * Tuple order drives the per-line truncation sequence and therefore the sealed
     * `total`, so it has to be reproducible. Same goods, same split, same bytes.
     */
    public function test_the_sealed_total_is_reproducible_across_two_identical_returns(): void
    {
        $totals = [];

        foreach ([0, 1] as $_) {
            $invoice = $this->invoiceWithLine('5.0000', '133.333', discountAmount: '7.000', taxRate: '19.00');
            $this->cfLinkInvoiceToDeliveryNotes($invoice, [
                $this->dn('3.0000', $this->cfLocationA->id, '133.333'),
                $this->dn('2.0000', $this->cfLocationB->id, '133.333'),
            ]);
            $this->cancelAlreadyReturned($invoice)->assertOk();
            $totals[] = (string) $this->returnNoteFor($invoice)?->total;
        }

        self::assertSame($totals[0], $totals[1]);
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

    private function invoiceWithLine(
        string $quantity,
        string $unitPrice,
        ?string $discountAmount = null,
        ?string $discountPercent = null,
        string $taxRate = '0.00',
    ): Document {
        $line = [
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
        ];
        if ($discountAmount !== null) {
            $line['discount_amount'] = $discountAmount;
        }
        if ($discountPercent !== null) {
            $line['discount_percent'] = $discountPercent;
        }

        return $this->cfPostedInvoice([$line], ['document_date' => $this->issuedOn]);
    }

    private function dn(string $quantity, string $locationId, string $unitPrice = '100.000'): Document
    {
        return $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]], ['location_id' => $locationId, 'document_date' => $this->issuedOn]);
    }

    private function returnNoteFor(Document $invoice): ?Document
    {
        return Document::query()
            ->where('type', DocumentType::ReturnNote)
            ->where('source_document_id', $invoice->id)
            ->where('status', '!=', DocumentStatus::Cancelled)
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
    private function sumDiscount(array $lines): string
    {
        $sum = '0.000';
        foreach ($lines as $line) {
            $sum = bcadd($sum, (string) ($line->discount_amount ?? '0'), 3);
        }

        return $sum;
    }

    /**
     * @param  array<int, DocumentLine>  $lines
     * @return array<string, string>
     */
    private function discountByLocation(array $lines): array
    {
        $byLocation = [];
        foreach ($lines as $line) {
            $byLocation[(string) $line->location_id] = (string) ($line->discount_amount ?? '0');
        }

        return $byLocation;
    }
}
