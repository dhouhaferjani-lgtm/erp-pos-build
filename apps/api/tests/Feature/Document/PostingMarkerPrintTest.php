<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * N-6 item 6 (owner ruling) — the not-yet-posted print marker, SUPERSEDED IN PLACE
 * by lane C-F0 (SPEC §2.4 / F-95).
 *
 * WHAT CHANGED AND WHY THE ASSERTIONS MOVED. N-6's fourth arm printed a warning
 * line beside a full VAT breakdown; C-F0 replaced that arm's copy with the
 * proforma banner and stripped the VAT from the page underneath it, because the
 * VAT mentioned on an issued invoice is owed by the act of issuing it (Code TVA
 * Art. 18) and a sentence next to the amount does not undo the amount. Every
 * assertion that named `documents.posting_marker.title` / `.detail` now names
 * `documents.proforma.title` / `.detail` — those two keys were DELETED from
 * `lang/{en,fr,ar}/documents.php`, and leaving the old names here would have made
 * each `assertStringNotContainsString` vacuously true against a raw key string.
 * The arm SELECTION is untouched, which is the property this class was written to
 * hold: the branch is on the SEAL, not on lifecycle status.
 *
 *
 * The one owner-ruled, fiscally-VISIBLE output of this lane. It shipped in r1
 * with no test at all, which is exactly how fiscal gate F-4 got in: the marker
 * gated on LIFECYCLE status, so a CANCELLED invoice — which keeps its
 * `fiscal_hash` and carries `fiscal_status = VOIDED` — printed the words
 * "carries no fiscal seal and no hash-chain entry" about a document that WAS
 * posted, sealed and chained. A false fiscal statement, in writing, on the one
 * output an auditor reads.
 *
 * The sealed fixtures carry `chain_sequence` as well as `fiscal_hash`: on
 * PostgreSQL `chk_fiscal_mandatory_core` requires BOTH for any non-NON_FISCAL
 * document, and a fixture that cannot exist in the database proves nothing.
 */
final class PostingMarkerPrintTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures('TN');
    }

    public function test_a_draft_invoice_prints_the_proforma_banner(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Draft));

        $this->assertStringContainsString(__('documents.proforma.title'), $html);
        $this->assertStringNotContainsString(__('documents.posting_marker.cancelled_title'), $html);
    }

    public function test_a_confirmed_invoice_prints_the_proforma_banner(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Confirmed));

        $this->assertStringContainsString(__('documents.proforma.title'), $html);
    }

    public function test_a_posted_invoice_prints_no_marker_at_all(): void
    {
        $html = $this->renderMarker($this->invoice(
            DocumentStatus::Posted,
            ['fiscal_hash' => str_repeat('a', 64), 'fiscal_status' => FiscalStatus::Sealed, 'chain_sequence' => 1],
        ));

        $this->assertSame('', trim($html), 'a posted reprint must be byte-unchanged');
    }

    public function test_a_settled_invoice_prints_no_marker_at_all(): void
    {
        $html = $this->renderMarker($this->invoice(
            DocumentStatus::Paid,
            ['fiscal_hash' => str_repeat('b', 64), 'fiscal_status' => FiscalStatus::Sealed, 'chain_sequence' => 2],
        ));

        $this->assertSame('', trim($html));
    }

    /**
     * F-4 — the regression this test exists for.
     */
    public function test_a_cancelled_invoice_says_cancelled_and_never_denies_its_seal(): void
    {
        $html = $this->renderMarker($this->invoice(
            DocumentStatus::Cancelled,
            ['fiscal_hash' => str_repeat('c', 64), 'fiscal_status' => FiscalStatus::Voided, 'chain_sequence' => 3],
        ));

        $this->assertStringContainsString(__('documents.posting_marker.cancelled_title'), $html);
        $this->assertStringNotContainsString(
            __('documents.proforma.title'),
            $html,
            'a sealed-then-cancelled invoice was issued with VAT and must never be re-rendered as an estimate',
        );
    }

    /**
     * R2-F1 [BLOCKING] — F-4 with the sign flipped.
     *
     * `RefundService::cancelInvoiceWithoutDecision()` writes `status = Cancelled`
     * directly onto a DRAFT or CONFIRMED invoice that was NEVER sealed (its
     * `Posted` arm delegates to `DocumentPostingService::cancel()`; its `Paid`
     * arm throws). The r1 branch was seal-AGNOSTIC, so such a document printed
     * "was posted and sealed … its fiscal seal remains in the hash chain" — a
     * false fiscal statement claiming a chain entry that does not exist.
     */
    public function test_a_cancelled_but_never_sealed_invoice_claims_no_seal(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Cancelled));

        $this->assertStringContainsString(__('documents.posting_marker.cancelled_title'), $html);
        $this->assertStringContainsString(__('documents.posting_marker.cancelled_unsealed_detail'), $html);
        $this->assertStringNotContainsString(
            __('documents.posting_marker.cancelled_detail'),
            $html,
            'a document that was never sealed must not be described as sealed and hash-chained',
        );
        $this->assertStringNotContainsString(__('documents.proforma.title'), $html);
    }

    /**
     * R2-F2 — opening-balance rows are real migrated production data
     * (`ArApOpeningService`: Posted, NON_FISCAL, no hash). "No fiscal seal" is
     * true of them; "has not been posted to the accounts" is not — they were
     * posted in the customer's previous system.
     */
    public function test_a_historical_opening_balance_invoice_is_not_called_unposted(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Posted, [
            'is_historical' => true,
            'fiscal_category' => FiscalCategory::NonFiscal,
        ]));

        $this->assertStringContainsString(__('documents.posting_marker.historical_title'), $html);
        $this->assertStringNotContainsString(
            __('documents.proforma.detail'),
            $html,
            'a Posted opening-balance row is not an estimate and must not be re-labelled as one',
        );
    }

    public function test_a_confirmed_credit_note_prints_the_proforma_banner_too(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        $this->assertStringContainsString(__('documents.proforma.title'), $this->renderMarker($creditNote));
    }

    public function test_a_non_fiscal_type_prints_no_marker(): void
    {
        $order = $this->invoice(DocumentStatus::Confirmed, ['type' => DocumentType::SalesOrder]);

        $this->assertSame('', trim($this->renderMarker($order)));
    }

    public function test_no_seal_hash_or_qr_block_is_emitted_for_an_unposted_document(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Confirmed));

        foreach (['fiscal_hash', 'previous_hash', 'chain_sequence', '<svg', 'qr'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($html));
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(DocumentStatus $status, array $overrides = []): Document
    {
        // The SEAL columns are applied in a SECOND write, on purpose. The shared
        // fixture builds the document, then adds lines, then re-totals it — and
        // on PostgreSQL `trg_document_immutability` refuses that re-total once
        // `fiscal_status` is SEALED/VOIDED. Sealing after the document is whole
        // passes, because the trigger reads OLD.fiscal_status (still DRAFT).
        $seal = array_intersect_key($overrides, array_flip(['fiscal_hash', 'fiscal_status', 'chain_sequence']));
        $rest = array_diff_key($overrides, $seal);

        $document = $this->dpConfirmedInvoice([$this->dpPhysicalLine()], array_merge(['status' => $status], $rest));

        if ($seal !== []) {
            $document->forceFill($seal)->save();
        }

        return $document->fresh();
    }

    private function renderMarker(Document $document): string
    {
        return view('documents.components.posting_marker', ['document' => $document])->render();
    }
}
