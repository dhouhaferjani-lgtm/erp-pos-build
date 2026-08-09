<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DTOs\DeliveredQuantityTuple;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveredQuantityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T15 (plan CF §3) — the delivered-quantity resolver, both linkage shapes, keyed by
 * `(product, location)`. [PG]
 *
 * This is the safety spine of the whole lane. Posting an invoice moves no stock, so
 * "does this invoice have product lines" is the wrong question — the right one is
 * "did units leave, how many, and from where". Getting the second half wrong is what
 * creates inventory that never existed.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class DeliveredQuantityResolverTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private DeliveredQuantityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-delivered-qty');
        $this->resolver = app(DeliveredQuantityResolver::class);
    }

    // ── linkage shapes ─────────────────────────────────────────────────────────

    public function test_a_dn_sourced_invoice_resolves_the_delivery_notes_quantity(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertCount(1, $tuples);
        self::assertSame($this->cfProduct->id, $tuples[0]->productId);
        self::assertSame($this->cfLocationA->id, $tuples[0]->locationId);
        self::assertSame('4.0000', $tuples[0]->delivered);
        self::assertSame('4.0000', $tuples[0]->remaining);
        self::assertTrue($this->resolver->hasGoodsIssued($invoice));
    }

    /**
     * The traversal fiscal gate N-I2 added. Without it an SO-driven tenant resolves
     * to ZERO delivered — fail-closed, so no phantom stock, but the whole guided
     * cancel flow would be silently unusable for genuinely delivered goods.
     */
    public function test_an_so_sourced_invoice_resolves_the_orders_delivery_note_quantities(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '3.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceViaSalesOrder($invoice, [$dn]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertCount(1, $tuples);
        self::assertSame('3.0000', $tuples[0]->delivered);
        self::assertTrue($this->resolver->hasGoodsIssued($invoice));
    }

    public function test_an_invoice_with_no_delivery_linkage_resolves_to_nothing(): void
    {
        $invoice = $this->invoiceForFiveUnits();

        self::assertSame([], $this->resolver->resolve($invoice));
        self::assertFalse(
            $this->resolver->hasGoodsIssued($invoice),
            'Fail closed: no linkage means nothing left the building.',
        );
    }

    public function test_a_draft_delivery_note_is_ignored(): void
    {
        $draftDn = $this->cfConfirmedDeliveryNote(
            [[
                'product_id' => $this->cfProduct->id,
                'quantity' => '4.0000',
                'unit_price' => '100.000',
            ]],
            [
                'status' => DocumentStatus::Draft,
                'fiscal_status' => FiscalStatus::Draft,
                'fiscal_hash' => null,
                'chain_sequence' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ],
        );
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$draftDn]);

        self::assertSame([], $this->resolver->resolve($invoice->refresh()));
        self::assertFalse($this->resolver->hasGoodsIssued($invoice));
    }

    /**
     * The cap is on DELIVERED, not invoiced: an invoice for 5 backed by a delivery of
     * 2 has 2 available to return, not 5.
     */
    public function test_a_partial_delivery_caps_at_the_delivered_amount_not_the_invoiced_one(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceViaSalesOrder($invoice, [$dn]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertSame('2.0000', $tuples[0]->remaining);
    }

    // ── tuple keying (fiscal gate N2-C1) ──────────────────────────────────────

    /**
     * "A product delivered 3 from L1 and 2 from L2" — across TWO delivery notes.
     * Keying by product alone would report one tuple of 5 with no tie-break rule, and
     * the caller would restock 5 into one location: 2 units created where they never
     * left.
     */
    public function test_multi_dn_consolidation_across_two_locations_returns_two_tuples(): void
    {
        $dnA = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '3.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationA->id]);

        $dnB = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationB->id]);

        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dnA, $dnB]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertCount(2, $tuples, 'Two locations must yield TWO tuples, not one tuple of the sum.');
        self::assertSame('5.0000', $this->sumRemaining($tuples));
        self::assertSame('3.0000', $this->remainingAt($tuples, $this->cfLocationA->id));
        self::assertSame('2.0000', $this->remainingAt($tuples, $this->cfLocationB->id));
    }

    /**
     * The same split WITHIN one delivery note, via per-line `location_id` — the shape
     * `DocumentLine::getEffectiveLocationId()`'s docblock exists for ("Per-line
     * location for multi-location orders") and `issueStock()` honours per line.
     */
    public function test_per_line_locations_inside_one_dn_also_return_two_tuples(): void
    {
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
        ], ['location_id' => $this->cfLocationA->id]);

        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertCount(2, $tuples);
        self::assertSame('3.0000', $this->remainingAt($tuples, $this->cfLocationA->id));
        self::assertSame('2.0000', $this->remainingAt($tuples, $this->cfLocationB->id));
    }

    /**
     * A prior return note nets against ITS OWN tuple only. If the netting were
     * per-product, returning from L1 would wrongly exhaust L2's remaining and the
     * goods at L2 could never come back.
     */
    public function test_a_prior_return_nets_against_its_own_tuple_only(): void
    {
        $dnA = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '3.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationA->id]);

        $dnB = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationB->id]);

        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dnA, $dnB]);

        $this->priorReturnNote($invoice, [[
            'product_id' => $this->cfProduct->id,
            'quantity' => '1.0000',
            'location_id' => $this->cfLocationA->id,
        ]]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertSame('2.0000', $this->remainingAt($tuples, $this->cfLocationA->id));
        self::assertSame(
            '2.0000',
            $this->remainingAt($tuples, $this->cfLocationB->id),
            "The other location's remaining must be untouched.",
        );
    }

    public function test_a_fully_returned_invoice_reports_zero_remaining_rather_than_no_tuple(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $this->priorReturnNote($invoice, [[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'location_id' => $this->cfLocationA->id,
        ]]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        // The tuple SURVIVES with remaining 0 — callers must be able to tell
        // "exhausted" from "never delivered".
        self::assertCount(1, $tuples);
        self::assertSame('0.0000', $tuples[0]->remaining);
        self::assertSame('2.0000', $tuples[0]->alreadyReturned);
        self::assertFalse($this->resolver->hasGoodsIssued($invoice));
    }

    public function test_a_cancelled_prior_return_does_not_net(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $rn = $this->priorReturnNote($invoice, [[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'location_id' => $this->cfLocationA->id,
        ]]);
        $rn->update(['status' => DocumentStatus::Cancelled]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertSame('2.0000', $tuples[0]->remaining);
    }

    /**
     * The capped-drain case. A hand-built return note whose line has neither a line
     * location nor a document location cannot be pinned to a tuple. Dumping the whole
     * remainder into the first tuple would let its zero-floor swallow the excess and
     * over-report remaining; draining with a per-tuple cap does not.
     *
     * 3 at L1 + 2 at L2, unattributable prior return of 4 ⇒ 3 netted at L1, 1 at L2,
     * 1 remaining overall. The uncapped bug would report 2.
     */
    public function test_an_unattributable_prior_return_drains_across_tuples_with_a_cap(): void
    {
        $dnA = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '3.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationA->id]);

        $dnB = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]], ['location_id' => $this->cfLocationB->id]);

        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dnA, $dnB]);

        // No line location AND no document location — only reachable by hand, since
        // the guided cancel flow always writes an explicit per-line location.
        $this->priorReturnNote(
            $invoice,
            [[
                'product_id' => $this->cfProduct->id,
                'quantity' => '4.0000',
                'location_id' => null,
            ]],
            ['location_id' => null],
        );

        $tuples = $this->resolver->resolve($invoice->refresh());

        self::assertSame('1.0000', $this->sumRemaining($tuples));
        self::assertTrue($this->resolver->hasGoodsIssued($invoice));
    }

    public function test_service_lines_on_the_delivery_note_contribute_no_tuple(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([
            $this->cfServiceLine(),
        ]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        self::assertSame([], $this->resolver->resolve($invoice->refresh()));
        self::assertFalse($this->resolver->hasGoodsIssued($invoice));
    }

    public function test_tuples_come_back_in_the_mandated_stable_sort_order(): void
    {
        $dn = $this->cfConfirmedDeliveryNote([
            [
                'product_id' => $this->cfProductTwo->id,
                'quantity' => '1.0000',
                'unit_price' => '50.000',
                'location_id' => $this->cfLocationB->id,
            ],
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '1.0000',
                'unit_price' => '100.000',
                'location_id' => $this->cfLocationB->id,
            ],
            [
                'product_id' => $this->cfProduct->id,
                'quantity' => '1.0000',
                'unit_price' => '100.000',
                'location_id' => $this->cfLocationA->id,
            ],
        ]);
        $invoice = $this->invoiceForFiveUnits();
        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        $tuples = $this->resolver->resolve($invoice->refresh());

        $keys = array_map(
            static fn (DeliveredQuantityTuple $t): string => $t->productId.'|'.$t->locationId,
            $tuples,
        );
        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys, 'Tuple order drives per-line truncation and therefore the sealed total.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function invoiceForFiveUnits(): Document
    {
        return $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '5.0000',
            'unit_price' => '100.000',
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function priorReturnNote(Document $invoice, array $lines, array $overrides = []): Document
    {
        $rn = Document::create(array_merge([
            'tenant_id' => $this->cfTenant->id,
            'company_id' => $this->cfCompany->id,
            'location_id' => $this->cfLocationA->id,
            'partner_id' => $this->cfPartner->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CF-RN-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'source_document_id' => $invoice->id,
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ], $overrides));

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $rn->id,
                'line_number' => $index + 1,
                'product_id' => $line['product_id'],
                'location_id' => $line['location_id'] ?? null,
                'description' => 'prior return',
                'quantity' => $line['quantity'],
                'unit_price' => '100.000',
                'tax_rate' => '0.00',
                'line_total' => '0.000',
            ]);
        }

        return $rn;
    }

    /**
     * @param  list<DeliveredQuantityTuple>  $tuples
     */
    private function sumRemaining(array $tuples): string
    {
        $sum = '0.0000';
        foreach ($tuples as $tuple) {
            $sum = bcadd($sum, $tuple->remaining, 4);
        }

        return $sum;
    }

    /**
     * @param  list<DeliveredQuantityTuple>  $tuples
     */
    private function remainingAt(array $tuples, string $locationId): string
    {
        foreach ($tuples as $tuple) {
            if ($tuple->locationId === $locationId) {
                return $tuple->remaining;
            }
        }

        self::fail("No tuple for location {$locationId}.");
    }
}
