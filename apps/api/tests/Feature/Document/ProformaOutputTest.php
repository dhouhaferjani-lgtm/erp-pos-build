<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;
use Tests\Traits\ExtractsPdfText;

/**
 * C-F0 / SPEC §2.4 (F-13, F-64, F-95; owner OQ-14 fail-safe default) — the printed
 * output of a CONFIRMED-but-unposted invoice or credit note is a PROFORMA.
 *
 * THE DEFECT. N-6 ruled that a confirmed invoice may be printed, and shipped a
 * marker line saying it is not yet booked. The document underneath the marker was
 * left untouched: it still carried the VAT column, the `Tax` total row, the
 * seller's VAT number and the fiscal identifiers. Under Code TVA Art. 18 the VAT
 * mentioned on an ISSUED invoice is owed by the mere fact of issuing it — a
 * numbered, VAT-bearing paper the ledger has never seen is a liability the tenant
 * did not choose to take on, and the recipient can deduct from it. A marker that
 * says "not yet posted" next to a VAT amount does not undo the VAT amount.
 *
 * THE INVARIANT (F-95). Such an output carries no `VAT` / `TVA` / `tax` / `TTC` /
 * `HT` label or amount, no rate column, no tax breakdown, no seal, hash, chain
 * sequence or QR block, and no "posted" / "comptabilisée" wording. It shows an
 * `Estimated total` and the line `Proforma — non-fiscal document`, in the tenant's
 * language.
 *
 * POSTED OUTPUT IS UNCHANGED, and that is pinned the only way it can be pinned:
 * against snapshots of the HTML and of the extracted PDF text taken on the BASE
 * commit, before a line of production code moved (see the handback for the
 * capture command). The PDF itself is not byte-comparable — dompdf stamps
 * `CreationDate` — so the comparison is over its text, which is what a reader
 * sees.
 *
 * WHY `<style>` IS STRIPPED BEFORE THE TOKEN SCAN. `layouts/document.blade.php`
 * carries one stylesheet shared by all eight templates, and it declares
 * `.status-posted`. A CSS class name is not a statement about this document; the
 * scan is over what is rendered, so the stylesheet and HTML comments come out
 * first. The PDF text needs no such treatment — it contains only drawn glyphs.
 */
final class ProformaOutputTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use ExtractsPdfText;
    use RefreshDatabase;

    /**
     * Every token F-95 forbids on a confirmed-unposted rendering.
     *
     * `\bHT\b` is anchored and case-SENSITIVE on purpose: an unanchored `ht`
     * matches `height`, `right` and `white`, and the PDF text of the marker is
     * upper-cased by the stylesheet, so `RIGHT` would match an unanchored one too.
     */
    private const FORBIDDEN_TOKENS = [
        '/VAT/i',
        '/TVA/i',
        '/\btax/i',
        '/TTC/i',
        '/\bHT\b/',
        '/fiscal_hash/i',
        '/chain/i',
        '/\bQR\b/i',
        '/posted/i',
        '/comptabilis/i',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures('TN');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localeProvider(): array
    {
        return ['english' => ['en'], 'french' => ['fr'], 'arabic' => ['ar']];
    }

    #[DataProvider('localeProvider')]
    public function test_a_confirmed_unposted_invoice_renders_as_a_proforma_in_html(string $locale): void
    {
        $this->app->setLocale($locale);
        $html = $this->renderHtml($this->unpostedInvoice());

        $this->assertNoForbiddenToken($this->scannable($html), "invoice HTML in {$locale}");
        $this->assertStringContainsString(__('documents.proforma.title'), $html);
        $this->assertStringContainsString(__('documents.proforma.estimated_total'), $html);
    }

    #[DataProvider('localeProvider')]
    public function test_a_confirmed_unposted_credit_note_renders_as_a_proforma_in_html(string $locale): void
    {
        $this->app->setLocale($locale);
        $html = $this->renderHtml($this->unpostedCreditNote());

        $this->assertNoForbiddenToken($this->scannable($html), "credit note HTML in {$locale}");
        $this->assertStringContainsString(__('documents.proforma.title'), $html);
        $this->assertStringContainsString(__('documents.proforma.estimated_total'), $html);
    }

    #[DataProvider('localeProvider')]
    public function test_a_confirmed_unposted_invoice_pdf_carries_no_forbidden_token(string $locale): void
    {
        $this->app->setLocale($locale);
        $text = $this->renderPdfText($this->unpostedInvoice());

        $this->assertNoForbiddenToken($text, "invoice PDF text in {$locale}");
    }

    #[DataProvider('localeProvider')]
    public function test_a_confirmed_unposted_credit_note_pdf_carries_no_forbidden_token(string $locale): void
    {
        $this->app->setLocale($locale);
        $text = $this->renderPdfText($this->unpostedCreditNote());

        $this->assertNoForbiddenToken($text, "credit note PDF text in {$locale}");
    }

    /**
     * The two strings F-95 names verbatim, on the PDF, in the two locales whose
     * glyphs the bundled DejaVu subset actually draws. Arabic is asserted on the
     * HTML above only: the PDF font stack does not shape Arabic (N-6 residual
     * R-4), which is the Arabic-PDF lane's problem and not a licence to leave the
     * VAT on the page — hence the token scan above covers `ar` too.
     */
    public function test_the_english_proforma_pdf_says_estimated_total_and_names_itself_a_proforma(): void
    {
        $this->app->setLocale('en');
        $text = $this->renderPdfText($this->unpostedInvoice());

        // The marker band is upper-cased by the shared stylesheet (`.posting-marker
        // strong { text-transform: uppercase }`), so the PDF draws PROFORMA — the
        // string is asserted exactly on the HTML above and case-insensitively here.
        $this->assertStringContainsStringIgnoringCase('Proforma', $text);
        $this->assertStringContainsStringIgnoringCase('non-fiscal document', $text);
        $this->assertStringContainsString('Estimated total', $text);
        $this->assertStringContainsString('238,000', $text, 'the estimated total is the gross figure, unlabelled');
    }

    public function test_the_french_proforma_pdf_is_translated_and_still_clean(): void
    {
        $this->app->setLocale('fr');
        $text = $this->renderPdfText($this->unpostedInvoice());

        $this->assertStringContainsString(mb_strtoupper(__('documents.proforma.title')), mb_strtoupper($text));
        $this->assertStringContainsString(mb_strtoupper(__('documents.proforma.estimated_total')), mb_strtoupper($text));
        $this->assertNoForbiddenToken($text, 'french invoice PDF text');
    }

    /**
     * A DRAFT is even further from definitive than a confirmed one: the fail-safe
     * default (OQ-14) applies to it too.
     */
    public function test_a_draft_invoice_renders_as_a_proforma_too(): void
    {
        $this->app->setLocale('en');
        $invoice = $this->unpostedInvoice();
        $invoice->forceFill(['status' => DocumentStatus::Draft])->save();
        $invoice->refresh();

        $this->assertNoForbiddenToken($this->scannable($this->renderHtml($invoice)), 'draft invoice HTML');
    }

    public function test_a_posted_invoice_renders_identically_to_the_pre_change_snapshot(): void
    {
        $this->assertPostedRenderingUnchanged($this->postedInvoice(), 'posted-invoice');
    }

    public function test_a_posted_credit_note_renders_identically_to_the_pre_change_snapshot(): void
    {
        $this->assertPostedRenderingUnchanged($this->postedCreditNote(), 'posted-credit-note');
    }

    /**
     * WHAT "UNCHANGED" MEANS HERE, precisely, because the word byte-identical is
     * doing real work in this lane's invariant.
     *
     *   1. The extracted PDF TEXT is compared BYTE for BYTE. That is the document
     *      — every label, every amount, in order, as the customer and the auditor
     *      read it. Nothing about a posted invoice may move, and nothing does.
     *      (The PDF *file* is not comparable: dompdf stamps `CreationDate`.)
     *   2. The HTML is compared with runs of whitespace collapsed, and its
     *      `<style>` block byte for byte on top of that. Gating a template means
     *      adding `@if`/`@php` lines and the comments that explain why they are
     *      there; Blade emits the indentation in front of each of those
     *      directives, so the HTML source gains whitespace that no renderer —
     *      HTML or dompdf — can see. Collapsing it compares every element,
     *      attribute, label and amount and ignores exactly the thing that changed.
     *      The stylesheet is compared strictly because a CSS edit WOULD be
     *      visible, and this lane makes none.
     *
     * The snapshots were captured on the base commit before any production file
     * moved; the capture command is in the handback.
     */
    private function assertPostedRenderingUnchanged(Document $posted, string $snapshot): void
    {
        $expectedHtml = (string) file_get_contents(__DIR__."/../../Fixtures/proforma/{$snapshot}.html");
        $actualHtml = $this->renderHtml($posted);

        $this->assertSame(
            $this->collapseWhitespace($expectedHtml),
            $this->collapseWhitespace($actualHtml),
            'the rendered posted document must be unchanged',
        );
        $this->assertSame(
            $this->styleBlock($expectedHtml),
            $this->styleBlock($actualHtml),
            'the shared stylesheet must be unchanged',
        );
        $this->assertSame(
            file_get_contents(__DIR__."/../../Fixtures/proforma/{$snapshot}.pdf.txt"),
            $this->renderPdfText($posted),
            'the posted PDF text must be byte-identical',
        );
    }

    private function collapseWhitespace(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    private function styleBlock(string $html): string
    {
        preg_match('#<style\b[^>]*>(.*?)</style>#si', $html, $match);

        return $match[1] ?? '';
    }

    /**
     * A posted invoice still carries every fiscal mention it is required to carry.
     * The snapshots above would also pass if the whole page went blank.
     */
    public function test_a_posted_invoice_still_shows_the_tax_breakdown(): void
    {
        $this->app->setLocale('en');
        $html = $this->renderHtml($this->postedInvoice());

        $this->assertStringContainsString('Tax', $html);
        $this->assertStringContainsString('38,000', $html);
        $this->assertStringContainsString('VAT', $html, 'the seller VAT number stays on a definitive invoice');
        $this->assertStringNotContainsString(__('documents.proforma.title'), $html);
    }

    private function renderHtml(Document $document): string
    {
        /** @var DocumentPdfService $service */
        $service = $this->app->make(DocumentPdfService::class);

        $template = $document->type === DocumentType::CreditNote
            ? 'documents.templates.credit_note'
            : 'documents.templates.invoice';

        return view($template, $service->viewDataFor($document))->render();
    }

    private function renderPdfText(Document $document): string
    {
        /** @var DocumentPdfService $service */
        $service = $this->app->make(DocumentPdfService::class);

        return $this->extractPdfText($service->generate($document)->output());
    }

    /**
     * The rendered document, without the shared stylesheet and HTML comments.
     */
    private function scannable(string $html): string
    {
        $stripped = preg_replace('#<style\b[^>]*>.*?</style>#si', '', $html) ?? $html;

        return preg_replace('/<!--.*?-->/s', '', $stripped) ?? $stripped;
    }

    private function assertNoForbiddenToken(string $subject, string $context): void
    {
        foreach (self::FORBIDDEN_TOKENS as $pattern) {
            $this->assertSame(
                0,
                preg_match_all($pattern, $subject, $hits),
                sprintf(
                    '%s must not contain %s — found: %s',
                    $context,
                    $pattern,
                    implode(', ', array_slice($hits[0], 0, 5)),
                ),
            );
        }
    }

    private function unpostedInvoice(): Document
    {
        return $this->deterministic(
            $this->dpConfirmedInvoice([$this->dpPhysicalLine()]),
            'INV-PROFORMA-0001',
        );
    }

    private function unpostedCreditNote(): Document
    {
        return $this->deterministic(
            $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]),
            'CN-PROFORMA-0001',
        );
    }

    private function postedInvoice(): Document
    {
        return $this->seal($this->deterministic(
            $this->dpConfirmedInvoice([$this->dpPhysicalLine()]),
            'INV-SNAPSHOT-0001',
        ), 1);
    }

    private function postedCreditNote(): Document
    {
        return $this->seal($this->deterministic(
            $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]),
            'CN-SNAPSHOT-0001',
        ), 2);
    }

    /**
     * Everything the templates render, pinned: no random document number, no
     * `now()` date, real fiscal identifiers on both parties (so hiding them is
     * observable) and a non-zero tax amount (the shared fixture builds documents
     * at 0.000 tax, which would make the whole invariant vacuous).
     */
    private function deterministic(Document $document, string $number): Document
    {
        $this->dpCompany->forceFill([
            'vat_number' => 'TN-VAT-1234567',
            'phone' => null,
            'email' => null,
            'registration_number' => null,
        ])->save();

        $this->dpPartner->forceFill(['tax_id' => 'CUST-FISCAL-9'])->save();

        $line = $document->lines->firstOrFail();
        $line->forceFill(['tax_rate' => '19.00'])->save();

        $document->forceFill([
            'document_number' => $number,
            'document_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'subtotal' => '200.000',
            'tax_amount' => '38.000',
            'total' => '238.000',
            'balance_due' => '238.000',
            'notes' => null,
        ])->save();

        $document->refresh();

        return $document;
    }

    private function seal(Document $document, int $sequence): Document
    {
        $document->forceFill([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => str_repeat((string) $sequence, 64),
            'chain_sequence' => $sequence,
        ])->save();

        $document->refresh();

        return $document;
    }
}
