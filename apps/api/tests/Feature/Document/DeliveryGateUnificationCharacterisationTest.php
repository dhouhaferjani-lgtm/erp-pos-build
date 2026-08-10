<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Services\DeliveredQuantityResolver;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25f characterisation**.
 *
 * Plan `plan-wave3.md` §T25f: *"characterise first — pin the exact set of invoice
 * shapes that post today … then assert the set is identical after the
 * unification, with the converted case now traversing the resolver."*
 *
 * This class is the fence. Every row of the table below describes what the
 * system does on `6626cb373`, BEFORE the two duplicated delivery gates
 * (`DocumentPostingService::validateDeliveryCompliance()` and
 * `InvoiceController::checkDeliveryNotesDelivered()`) are unified onto
 * `DeliveredQuantityResolver`. T25f is deliberately ADDITIVE: after the
 * unification every single one of these assertions must STILL hold.
 *
 * | # | shape                                        | today  |
 * |---|----------------------------------------------|--------|
 * | 1 | order-sourced, DN confirmed & fully delivered | posts  |
 * | 2 | order-sourced, DN short-delivered            | refused|
 * | 3 | order-sourced, order has NO delivery notes   | refused|
 * | 4 | order-sourced, DN still DRAFT                | refused|
 * | 5 | converted (`payload.source_delivery_note_ids`)| posts  |
 * | 6 | standalone, physical lines                    | posts  |
 * | 7 | service-only                                  | posts  |
 * | 8 | credit note with physical lines               | posts  |
 *
 * Row 6 is the ONLY row T25b is permitted to change (posts → refused), and row 5
 * is the row that proves T25f had to land first: flipping row 6 while the gate
 * reads only the order-payload shape would have taken row 5 down with it.
 *
 * @see DeliveredQuantityResolver
 */
class DeliveryGateUnificationCharacterisationTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
    }

    private function postingService(): DocumentPostingService
    {
        return app(DocumentPostingService::class);
    }

    // ── Row 1 ────────────────────────────────────────────────────────────────

    public function test_row1_order_sourced_with_fully_delivered_dn_posts(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $posted = $this->postingService()->post($invoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
    }

    // ── Row 2 ────────────────────────────────────────────────────────────────

    public function test_row2_order_sourced_with_short_delivered_dn_is_refused(): void
    {
        // A confirmed DN whose `quantity_delivered` was subsequently reduced below
        // `quantity` — the shape the shipped gate refuses with
        // "must be marked as fully delivered".
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('5.0000')]);
        $deliveryNote->lines()->update(['quantity_delivered' => '2.0000']);

        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('5.0000')]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/fully delivered/');

        $this->postingService()->post($invoice);
    }

    // ── Row 3 ────────────────────────────────────────────────────────────────

    public function test_row3_order_sourced_with_no_delivery_notes_is_refused(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShapeWithNoDeliveryNotes($invoice);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/must be delivered before posting/');

        $this->postingService()->post($invoice);
    }

    // ── Row 4 ────────────────────────────────────────────────────────────────

    public function test_row4_order_sourced_with_draft_dn_is_refused(): void
    {
        $draft = $this->dpDraftDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$draft]);

        $this->expectException(\DomainException::class);

        $this->postingService()->post($invoice);
    }

    // ── Row 5 — the population T25f exists to protect ────────────────────────

    public function test_row5_converted_invoice_posts(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $posted = $this->postingService()->post($invoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
    }

    // ── Row 6 — the ONLY row T25b may flip ───────────────────────────────────

    public function test_row6_standalone_physical_invoice_posts_before_t25b(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $posted = $this->postingService()->post($invoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
    }

    // ── Row 7 ────────────────────────────────────────────────────────────────

    public function test_row7_service_only_invoice_posts(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        $posted = $this->postingService()->post($invoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
    }

    // ── Row 8 ────────────────────────────────────────────────────────────────

    public function test_row8_credit_note_with_physical_lines_posts(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        $posted = $this->postingService()->post($creditNote);

        $this->assertSame(DocumentStatus::Posted, $posted->status);
    }

    // ── The resolver's blind spot, pinned BEFORE the unification ─────────────

    /**
     * The defect T25f fixes, stated as an assertion: the shipped gate is blind to
     * the converted shape. `DeliveredQuantityResolver` sees the DN; the gate does
     * not. Once the gate reads the resolver this test still passes — it becomes
     * the regression test proving the unification is what closed the gap.
     */
    public function test_resolver_sees_the_converted_shape_that_the_shipped_gates_cannot(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $resolver = app(DeliveredQuantityResolver::class);

        $this->assertSame(
            [$deliveryNote->id],
            $resolver->confirmedDeliveryNoteIdsFor($invoice),
            'The resolver reads payload.source_delivery_note_ids; the pre-T25f gates read only the order shape.',
        );

        // ...and the invoice has NO source order at all, which is exactly why both
        // shipped gates take their "standalone, no delivery check needed" branch.
        $this->assertNull($invoice->source_document_id);
    }
}
