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
 * (`apps/web/src/features/documents/CreateReturnNotePage.tsx`, mutationFn) and
 * pins the refusal. It is deliberately a characterisation test of a DEFECT: the
 * "full" and "partial" cases below flip from 422 to 201 when T8 repairs the
 * client, and the flip is the acceptance signal. The `it_accepts_the_backend_…`
 * case pins the shape T8 must produce, so the two halves of the fence can never
 * be satisfied by weakening only one of them.
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
     * FULL mode: the page sends no `lines` key at all, plus four keys the server
     * has never known (`source_invoice_id`, `auto_create_credit_note`,
     * `refund_method`, and no `partner_id`/`document_date`).
     */
    public function test_web_full_mode_payload_is_rejected_for_every_missing_key(): void
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

        // The three keys the request layer requires and the page never sends.
        $errors = $response->json('error.errors') ?? $response->json('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('partner_id', $errors);
        self::assertArrayHasKey('document_date', $errors);
        self::assertArrayHasKey('lines', $errors);

        // `source_invoice_id` is a query FILTER on the index endpoint, never a
        // create key — so the link the user asked for is silently absent.
        self::assertDatabaseMissing('documents', [
            'type' => DocumentType::ReturnNote->value,
            'source_document_id' => $invoice->id,
        ]);
    }

    /**
     * PARTIAL mode: the page sends `lines[].{line_id, quantity}`. `line_id` is
     * consumed by a different endpoint entirely (`CreditNoteService`), and each
     * line fails the two rules it omits.
     */
    public function test_web_partial_mode_payload_is_rejected_for_line_shape(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '5.0000',
            'unit_price' => '100.000',
        ]]);
        $invoice->load('lines');
        $sourceLineId = (string) $invoice->lines->first()?->id;

        $response = $this->actingAs($this->cfUser, 'sanctum')
            ->postJson('/api/v1/return-notes', [
                'return_reason' => 'defective',
                'notes' => 'Partial return',
                'auto_create_credit_note' => false,
                'source_invoice_id' => $invoice->id,
                'lines' => [
                    ['line_id' => $sourceLineId, 'quantity' => 2],
                ],
            ]);

        $response->assertStatus(422);

        $errors = $response->json('error.errors') ?? $response->json('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('partner_id', $errors);
        self::assertArrayHasKey('document_date', $errors);
        self::assertArrayHasKey('lines.0.description', $errors);
        self::assertArrayHasKey('lines.0.unit_price', $errors);

        self::assertDatabaseMissing('documents', [
            'type' => DocumentType::ReturnNote->value,
            'source_document_id' => $invoice->id,
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
