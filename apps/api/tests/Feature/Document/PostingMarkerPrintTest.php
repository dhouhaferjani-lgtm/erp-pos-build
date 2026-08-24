<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * N-6 item 6 (owner ruling) — the not-yet-posted print marker.
 *
 * The one owner-ruled, fiscally-VISIBLE output of this lane. It shipped in r1
 * with no test at all, which is exactly how fiscal gate F-4 got in: the marker
 * gated on LIFECYCLE status, so a CANCELLED invoice — which keeps its
 * `fiscal_hash` and carries `fiscal_status = VOIDED` — printed the words
 * "carries no fiscal seal and no hash-chain entry" about a document that WAS
 * posted, sealed and chained. A false fiscal statement, in writing, on the one
 * output an auditor reads.
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

    public function test_a_draft_invoice_prints_the_not_yet_posted_marker(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Draft));

        $this->assertStringContainsString(__('documents.posting_marker.title'), $html);
        $this->assertStringNotContainsString(__('documents.posting_marker.cancelled_title'), $html);
    }

    public function test_a_confirmed_invoice_prints_the_not_yet_posted_marker(): void
    {
        $html = $this->renderMarker($this->invoice(DocumentStatus::Confirmed));

        $this->assertStringContainsString(__('documents.posting_marker.title'), $html);
    }

    public function test_a_posted_invoice_prints_no_marker_at_all(): void
    {
        $html = $this->renderMarker($this->invoice(
            DocumentStatus::Posted,
            ['fiscal_hash' => str_repeat('a', 64), 'fiscal_status' => FiscalStatus::Sealed],
        ));

        $this->assertSame('', trim($html), 'a posted reprint must be byte-unchanged');
    }

    public function test_a_settled_invoice_prints_no_marker_at_all(): void
    {
        $html = $this->renderMarker($this->invoice(
            DocumentStatus::Paid,
            ['fiscal_hash' => str_repeat('b', 64), 'fiscal_status' => FiscalStatus::Sealed],
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
            ['fiscal_hash' => str_repeat('c', 64), 'fiscal_status' => FiscalStatus::Voided],
        ));

        $this->assertStringContainsString(__('documents.posting_marker.cancelled_title'), $html);
        $this->assertStringNotContainsString(
            __('documents.posting_marker.title'),
            $html,
            'a sealed-then-cancelled invoice must never be described as unsealed',
        );
    }

    public function test_a_confirmed_credit_note_prints_the_marker_too(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        $this->assertStringContainsString(__('documents.posting_marker.title'), $this->renderMarker($creditNote));
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
        return $this->dpConfirmedInvoice([$this->dpPhysicalLine()], array_merge(['status' => $status], $overrides));
    }

    private function renderMarker(Document $document): string
    {
        return view('documents.components.posting_marker', ['document' => $document])->render();
    }
}
