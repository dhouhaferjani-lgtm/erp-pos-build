<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveredQuantityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25f, D-29**: the two delivery predicates pinned
 * side by side so neither can be "simplified" into the other later.
 *
 * `hasGoodsIssued()`   — are units still OUT?        (restock capacity)
 * `hasEverIssuedGoods()` — did delivery HAPPEN?      (compliance)
 *
 * They diverge on exactly one population, and that population is the reason
 * D-29 exists: **an invoice delivered in full and then returned in full.** Using
 * the restock predicate as the pre-delivery-invoicing gate would refuse such an
 * invoice on the fiscal posting chokepoint — a document that was delivered,
 * blocked forever because the goods later came back.
 */
class EverIssuedGoodsPredicateTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
    }

    private function resolver(): DeliveredQuantityResolver
    {
        return app(DeliveredQuantityResolver::class);
    }

    public function test_both_predicates_are_false_for_a_standalone_invoice(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $this->assertFalse($this->resolver()->hasEverIssuedGoods($invoice));
        $this->assertFalse($this->resolver()->hasGoodsIssued($invoice));
    }

    public function test_both_predicates_are_true_for_a_delivered_invoice(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('3.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $this->assertTrue($this->resolver()->hasEverIssuedGoods($invoice));
        $this->assertTrue($this->resolver()->hasGoodsIssued($invoice));
    }

    /**
     * THE divergence. Delivered 3, returned 3.
     *
     * `hasGoodsIssued()` = false — nothing left to restock, and that is CORRECT
     * for its purpose (the guided cancel's over-return cap depends on it).
     * `hasEverIssuedGoods()` = true — delivery happened; the invoice is compliant.
     */
    public function test_a_delivered_then_fully_returned_invoice_diverges(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('3.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $this->returnEverything($invoice, '3.0000');

        $this->assertTrue(
            $this->resolver()->hasEverIssuedGoods($invoice),
            'Goods WERE issued — a later return does not un-deliver them.',
        );
        $this->assertFalse(
            $this->resolver()->hasGoodsIssued($invoice),
            'Nothing is left to restock — hasGoodsIssued() must keep its restock-capacity meaning.',
        );
    }

    /**
     * A DRAFT delivery note is not a delivery. Fail closed on both.
     */
    public function test_a_draft_delivery_note_issues_nothing(): void
    {
        $draft = $this->dpDraftDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$draft]);

        $this->assertFalse($this->resolver()->hasEverIssuedGoods($invoice));
        $this->assertFalse($this->resolver()->hasGoodsIssued($invoice));
    }

    /**
     * The order shape resolves too — `hasEverIssuedGoods()` inherits
     * `confirmedDeliveryNotesFor()`'s two-shape traversal rather than growing a
     * third one (D-17).
     */
    public function test_the_order_linkage_shape_resolves_as_well(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->assertTrue($this->resolver()->hasEverIssuedGoods($invoice));
    }

    /**
     * A confirmed return note against the invoice, restocking `$quantity` of the
     * fixture product from the fixture location.
     */
    private function returnEverything(Document $invoice, string $quantity): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'location_id' => $this->dpLocation->id,
            'source_document_id' => $invoice->id,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Confirmed,
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'document_number' => 'DP-RN-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => '3E return line',
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '0.000',
        ]);
    }
}
