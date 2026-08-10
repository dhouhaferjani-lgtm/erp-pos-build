<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Inventory\Domain\StockMovement;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25c / D-30**: the REQUIRED path, end to end.
 *
 * A compliance gate whose compliant path is unreachable does not produce
 * compliance; it produces back-dating and cancel-and-recreate. T25b refuses the
 * standalone goods invoice — this is where that invoice goes.
 *
 * The load-bearing step is **step 2, the linkage write**. Revision 2 of the plan
 * omitted it entirely, and without it create → confirm → re-post is refused
 * AGAIN: nothing else in the system writes
 * `invoice.payload['source_delivery_note_ids']` except the DN → invoice
 * converter. {@see test_without_the_linkage_the_repost_is_still_refused} pins
 * exactly that, because it is the defect this task fixes.
 */
class StandaloneInvoiceGuidedDeliveryTest extends TestCase
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
     * 🚨 ONE TEST, ONE LEDGER — the acceptance criterion of D-30.
     */
    public function test_the_guided_flow_creates_confirms_links_and_posts(): void
    {
        $invoice = $this->dpConfirmedInvoice([
            $this->dpPhysicalLine('2.0000'),
            $this->dpServiceLine(),
        ]);

        $movementsBefore = StockMovement::query()->count();

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'posted');

        $invoice->refresh();

        // The invoice is posted and sealed.
        $this->assertSame(DocumentStatus::Posted, $invoice->status);
        $this->assertNotNull($invoice->fiscal_hash);

        // A delivery note was created AND confirmed.
        $deliveryNote = Document::query()
            ->where('company_id', $this->dpCompany->id)
            ->where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(DocumentStatus::Confirmed, $deliveryNote->status);
        $this->assertNotNull($deliveryNote->fiscal_hash);

        // Step 2 — the linkage the resolver reads.
        $this->assertSame(
            [$deliveryNote->id],
            $invoice->payload['source_delivery_note_ids'],
        );

        // Exactly ONE stock movement per PHYSICAL line — the service line moves
        // nothing, and nothing is issued twice.
        $this->assertSame(
            $movementsBefore + 1,
            StockMovement::query()->count(),
            'One inventory exit per physical line; the service line must not move stock.',
        );

        // 📌 DEVIATION, DISCLOSED: the factory copies a NULL-`product_id` line
        // (the service shape) exactly as `SalesOrderToInvoiceConverter` always
        // did — it skips only lines whose product resolves and is NOT physical.
        // T25c preserves that rather than tightening it, because the tightening
        // is D-19 / T4's disclosed behaviour change and T4 owns
        // `PhysicalLinePredicate`. It is inert here: the assertion above proves
        // the service line moves no stock.
        $this->assertCount(2, $deliveryNote->lines);
        $this->assertSame(
            1,
            $deliveryNote->lines->whereNotNull('product_id')->count(),
            'Exactly one goods line, and it is the physical one.',
        );
    }

    /**
     * 🆕 THE NEGATIVE THAT NAMES THE DEFECT.
     *
     * Create a delivery note and confirm it, but skip the linkage write. The
     * resolver cannot see it, `hasEverIssuedGoods()` stays false, and the invoice
     * is refused a second time — with a confirmed delivery note and real stock
     * movements already on the ledger. That is the trap D-30 exists to close.
     */
    public function test_without_the_linkage_the_repost_is_still_refused(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        // A real, confirmed delivery note for the same goods — but no linkage.
        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('2.0000')]);

        $this->expectException(DeliveryRequiredBeforeInvoiceException::class);

        app(DocumentPostingService::class)->post($invoice);
    }

    /**
     * The endpoint is for the refused population ONLY. An order-sourced invoice
     * with draft delivery notes has a different remedy (confirm-deliveries-and-post)
     * and must not be routed here.
     */
    public function test_the_endpoint_refuses_an_invoice_that_does_not_need_a_delivery_note(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_CREATION_NOT_APPLICABLE');
        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
    }

    /**
     * No regression on the order-sourced flow: it still goes through
     * confirm-deliveries-and-post, which the extraction did not touch.
     */
    public function test_the_order_sourced_flow_is_unchanged(): void
    {
        $draft = $this->dpDraftDeliveryNote([$this->dpPhysicalLine()], ['payload' => ['auto_created' => true]]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$draft]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/confirm-deliveries-and-post");

        $response->assertStatus(200);
        $this->assertSame(DocumentStatus::Posted, $invoice->refresh()->status);
    }

    /**
     * The whole composite is ONE transaction: nothing is left half-done. Post the
     * same invoice twice — the second call finds it already posted and must not
     * generate a second delivery note or a second stock movement.
     */
    public function test_the_composite_is_not_repeatable_once_the_invoice_is_posted(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        $movementsAfterFirst = StockMovement::query()->count();

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(422);

        $this->assertSame($movementsAfterFirst, StockMovement::query()->count());
        $this->assertSame(
            1,
            Document::query()
                ->where('type', DocumentType::DeliveryNote)
                ->where('source_document_id', $invoice->id)
                ->count(),
        );
    }
}
