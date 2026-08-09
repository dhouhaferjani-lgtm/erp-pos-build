<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteData;
use App\Modules\Document\Domain\DTOs\CreateReturnNoteLineData;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Exceptions\ReturnQuantityExceededException;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T2 (plan CF §3) — `ReturnNoteService::createDraft()`.
 *
 * The draft-create body moved out of `ReturnNoteController::store()` so the guided
 * cancel flow reaches it through the SAME domain path a manual
 * `POST /return-notes` takes (CF-D1; the owner ruling's "no side-channel writers").
 * These tests pin the three properties the extraction has to preserve or add:
 *
 *  1. the create works with AND without a document-level `location_id` — the
 *     composite path leaves it NULL and writes per-line locations instead (CF-D11),
 *     so a service that quietly required one would break the whole cancel flow;
 *  2. the over-return cap still refuses with the SAME code and the SAME five
 *     `details` keys the old Presentation `HttpResponseException` carried, now as
 *     a typed domain exception (fiscal gate I-8);
 *  3. `createDraft()` assumes an open transaction and resolves scale from the
 *     ENTITY currency, never a bare `getScale()` (rule 19 context-safety).
 */
final class ReturnNoteCreateDraftServiceTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private ReturnNoteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-create-draft');
        $this->service = app(ReturnNoteService::class);
    }

    public function test_creates_a_draft_with_a_document_level_location(): void
    {
        $returnNote = DB::transaction(fn (): Document => $this->service->createDraft(
            new CreateReturnNoteData(
                partnerId: $this->cfPartner->id,
                documentDate: now(),
                lines: [new CreateReturnNoteLineData(
                    productId: $this->cfProduct->id,
                    description: 'CF Physical Product',
                    quantity: '2.0000',
                    unitPrice: '100.000',
                    taxRate: '19.00',
                )],
                currency: 'TND',
                locationId: $this->cfLocationA->id,
                returnReason: 'defective',
                returnCondition: 'damaged',
            ),
            $this->cfCompany,
        ));

        self::assertSame(DocumentStatus::Draft, $returnNote->status);
        self::assertSame(FiscalStatus::Draft, $returnNote->fiscal_status);
        self::assertSame($this->cfLocationA->id, $returnNote->location_id);
        self::assertSame('200.000', $returnNote->subtotal);
        self::assertSame('38.000', $returnNote->tax_amount);
        self::assertSame('238.000', $returnNote->total);
        self::assertSame('defective', $returnNote->payload['return_reason'] ?? null);
        self::assertSame('damaged', $returnNote->payload['return_condition'] ?? null);
        self::assertStringStartsWith('RN-', (string) $returnNote->document_number);
    }

    /**
     * The composite cancel path's shape: no document location at all, the real
     * location on each line. `getEffectiveLocationId()` must never have to fall
     * through to a null document location (CF-D11).
     */
    public function test_creates_a_draft_with_no_document_location_and_per_line_locations(): void
    {
        $returnNote = DB::transaction(fn (): Document => $this->service->createDraft(
            new CreateReturnNoteData(
                partnerId: $this->cfPartner->id,
                documentDate: now(),
                lines: [
                    new CreateReturnNoteLineData(
                        productId: $this->cfProduct->id,
                        description: 'CF Physical Product',
                        quantity: '3.0000',
                        unitPrice: '100.000',
                        locationId: $this->cfLocationA->id,
                    ),
                    new CreateReturnNoteLineData(
                        productId: $this->cfProduct->id,
                        description: 'CF Physical Product',
                        quantity: '2.0000',
                        unitPrice: '100.000',
                        locationId: $this->cfLocationB->id,
                    ),
                ],
                currency: 'TND',
                locationId: null,
            ),
            $this->cfCompany,
        ));

        self::assertNull($returnNote->location_id);
        self::assertCount(2, $returnNote->lines);

        // Repeated product_id with DISTINCT location_id is already valid — no
        // `distinct` rule exists on `lines.*.product_id` — and each line carries
        // its own explicit location, so nothing falls back.
        $byLocation = $returnNote->lines
            ->mapWithKeys(static fn ($line): array => [(string) $line->location_id => (string) $line->quantity])
            ->all();
        self::assertSame('3.0000', $byLocation[$this->cfLocationA->id]);
        self::assertSame('2.0000', $byLocation[$this->cfLocationB->id]);
        foreach ($returnNote->lines as $line) {
            self::assertSame($line->location_id, $line->getEffectiveLocationId());
        }
    }

    public function test_the_flat_line_discount_is_subtracted_from_the_line_net(): void
    {
        $returnNote = DB::transaction(fn (): Document => $this->service->createDraft(
            new CreateReturnNoteData(
                partnerId: $this->cfPartner->id,
                documentDate: now(),
                lines: [new CreateReturnNoteLineData(
                    productId: $this->cfProduct->id,
                    description: 'CF Physical Product',
                    quantity: '5.0000',
                    unitPrice: '100.000',
                    taxRate: '19.00',
                    discountAmount: '5.000',
                )],
                currency: 'TND',
                locationId: $this->cfLocationA->id,
            ),
            $this->cfCompany,
        ));

        // 5 × 100.000 − 5.000 = 495.000, VAT 19% = 94.050.
        self::assertSame('495.000', $returnNote->subtotal);
        self::assertSame('94.050', $returnNote->tax_amount);
        self::assertSame('589.050', $returnNote->total);
    }

    public function test_the_cap_refuses_with_the_same_code_and_details_as_before_the_extraction(): void
    {
        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '3.0000',
            'unit_price' => '100.000',
        ]]);

        try {
            DB::transaction(fn (): Document => $this->service->createDraft(
                new CreateReturnNoteData(
                    partnerId: $this->cfPartner->id,
                    documentDate: now(),
                    lines: [new CreateReturnNoteLineData(
                        productId: $this->cfProduct->id,
                        description: 'CF Physical Product',
                        quantity: '5.0000',
                        unitPrice: '100.000',
                    )],
                    currency: 'TND',
                    sourceDocumentId: $invoice->id,
                    locationId: $this->cfLocationA->id,
                ),
                $this->cfCompany,
            ));
            self::fail('Expected ReturnQuantityExceededException.');
        } catch (ReturnQuantityExceededException $e) {
            self::assertSame(ReturnQuantityExceededException::CODE_INVOICED, $e->refusalCode);
            self::assertSame([
                'product_id' => $this->cfProduct->id,
                'requested' => '5.0000',
                'remaining_returnable' => '3.0000',
                'invoiced' => '3.0000',
                // Bare '0', not '0.0000' — the pre-T2 body's `?? '0'` fallback,
                // preserved byte for byte so no consumer of the old envelope moves.
                'already_returned' => '0',
            ], $e->details());
        }

        // The refusal rolls the whole create back — no orphan document number
        // burnt into a half-built return note.
        self::assertDatabaseMissing('documents', [
            'source_document_id' => $invoice->id,
        ]);
    }

    /**
     * A zero-quantity line never reaches the database, because it would still
     * enter the SEALED `total` (`receiveStockBack()` skips it at
     * `ReturnNoteService.php:178-181`, the totals pass does not). The standalone
     * route is guarded by `lines.*.quantity` `gt:0`; the composite path does not
     * pass through that request at all, so the DTO itself refuses.
     */
    public function test_a_zero_quantity_line_is_refused_by_the_dto(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CreateReturnNoteLineData(
            productId: $this->cfProduct->id,
            description: 'CF Physical Product',
            quantity: '0.0000',
            unitPrice: '100.000',
        );
    }
}
