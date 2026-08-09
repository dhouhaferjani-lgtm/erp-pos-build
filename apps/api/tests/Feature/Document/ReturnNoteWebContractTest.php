<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T1 (plan CF §3) — the two-sides-agree fence for `POST /api/v1/return-notes`.
 *
 * The web app has NEVER successfully created a return note. Backend tests post
 * the real `CreateDocumentRequest` contract by hand
 * (`ReturnNoteIntegrationTest::it_creates_a_draft_return_note`), so the server is
 * green while the client's body is rejected outright — and the FE tests assert
 * rendering and query keys, never the posted body. Nothing on either side
 * described the gap, so nothing caught it.
 *
 * This class posts the EXACT body the web app builds
 * (`apps/web/src/features/documents/CreateReturnNotePage.tsx`, `buildCreatePayload`)
 * and pins the result.
 *
 * ── T8 HAS LANDED, SO THE FENCE HAS FLIPPED ────────────────────────────────────
 * Before T8 the two cases below posted the client's invented body and asserted 422
 * on `partner_id`, `document_date`, `lines`, `lines.*.description` and
 * `lines.*.unit_price`. They now post the REPAIRED body and assert 201. That flip is
 * the acceptance signal for T8, and keeping the class means the two sides cannot
 * drift apart again silently: if either the page's payload or the server's contract
 * moves without the other, exactly one of these fails.
 *
 * The historical 422 case is retained (`test_the_pre_t8_web_payload_is_still_refused`)
 * so the repair cannot be "undone" by loosening validation instead — the old body must
 * KEEP failing.
 */
final class ReturnNoteWebContractTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-web-contract');
    }

    /**
     * FULL mode, POST-T8: every physical line of the source document, in the canonical
     * shape. This is `buildCreatePayload()` with `lineMode === 'all'`.
     */
    public function test_web_full_mode_payload_is_accepted(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]]);

        $invoice->load('lines');

        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => now()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $invoice->id,
                'return_reason' => 'defective',
                'return_condition' => 'damaged',
                'notes' => 'Customer returned defective product',
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '2.0000',
                    'unit_price' => '100.000',
                    'tax_rate' => '0.00',
                ]],
            ]);

        $response->assertCreated();

        self::assertDatabaseHas('documents', [
            'type' => DocumentType::ReturnNote->value,
            'source_document_id' => $invoice->id,
        ]);
    }

    /**
     * The historical defect, kept red-on-the-old-shape. If a future change "repairs"
     * the client by loosening server validation instead, this fails.
     */
    public function test_the_pre_t8_web_payload_is_still_refused(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '2.0000',
            'unit_price' => '100.000',
        ]]);

        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'return_reason' => 'defective',
                'return_condition' => 'damaged',
                'refund_method' => 'store_credit',
                'notes' => 'Customer returned defective product',
                'auto_create_credit_note' => false,
                'source_invoice_id' => $invoice->id,
            ]);

        $response->assertStatus(422);

        $errors = $response->json('error.errors') ?? $response->json('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('partner_id', $errors);
        self::assertArrayHasKey('document_date', $errors);
        self::assertArrayHasKey('lines', $errors);

        // `source_invoice_id` is a query FILTER on the index endpoint, never a create
        // key — so the link the user asked for was silently absent.
        self::assertDatabaseMissing('documents', [
            'type' => DocumentType::ReturnNote->value,
            'source_document_id' => $invoice->id,
        ]);
    }

    /**
     * PARTIAL mode, POST-T8: only the selected lines, at their own quantities, as REAL
     * lines rather than the `{line_id, quantity}` shape whose `line_id` is consumed by
     * a different endpoint entirely.
     */
    public function test_web_partial_mode_payload_is_accepted(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '5.0000',
            'unit_price' => '100.000',
        ]]);
        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => now()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $invoice->id,
                'return_reason' => 'defective',
                'notes' => 'Partial return',
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    // A fractional quantity as a decimal STRING — impossible before T8,
                    // which floored the input at 1 and ran `parseInt`.
                    'quantity' => '0.5000',
                    'unit_price' => '100.000',
                    'tax_rate' => '0.00',
                ]],
            ]);

        $response->assertCreated();

        self::assertDatabaseHas('document_lines', [
            'product_id' => $this->cfProduct->id,
            'quantity' => '0.5000',
        ]);
    }

    /**
     * The other half of the fence: the canonical document-create shape the
     * backend has always accepted, which T8 makes the client send. If a future
     * change "fixes" the two cases above by loosening validation instead, this
     * case keeps the canonical shape pinned.
     */
    public function test_backend_canonical_payload_creates_a_draft_return_note(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '5.0000',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
        ]]);

        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'partner_id' => $this->cfPartner->id,
                'document_date' => now()->toDateString(),
                'currency' => 'TND',
                'source_document_id' => $invoice->id,
                'return_reason' => 'defective',
                'return_condition' => 'damaged',
                'notes' => 'Partial return',
                'lines' => [[
                    'product_id' => $this->cfProduct->id,
                    'description' => 'CF Physical Product',
                    'quantity' => '2.0000',
                    'unit_price' => '100.000',
                    'tax_rate' => '19.00',
                    'location_id' => $this->cfLocationA->id,
                ]],
            ]);

        $response->assertCreated();

        self::assertDatabaseHas('documents', [
            'type' => DocumentType::ReturnNote->value,
            'source_document_id' => $invoice->id,
        ]);
    }
}
