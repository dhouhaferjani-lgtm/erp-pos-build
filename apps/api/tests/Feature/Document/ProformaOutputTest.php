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

    /**
     * FIX ROUND r1 / gate F-1 [BLOCKING] — the predicate admits FOUR statuses and
     * the r1 matrix asserted two.
     *
     * `RepairPaidNeverPostedDocumentsCommand:118-119` selects exactly
     * `type = Invoice ∧ status = Paid` with no seal: tenant #1's INV-2026-0003
     * population, real rows. Those printed `PAID` on a page from which this lane
     * had already deleted the Paid and Balance Due rows — the document contradicted
     * itself. The `Posted`-never-sealed sibling was worse: the badge is
     * upper-cased into the PDF by `.status-badge { text-transform: uppercase }`,
     * so the page said `POSTED` — token 9 of FORBIDDEN_TOKENS, on the document
     * this lane certifies as non-definitive.
     *
     * The fix is the whole badge, not the word: a proforma states no lifecycle
     * status at all. There is nothing true a lifecycle badge can say about a
     * document whose own banner says it has not been entered anywhere.
     *
     * @return array<string, array{0: DocumentStatus}>
     */
    public static function unsealedStatusProvider(): array
    {
        return [
            'draft' => [DocumentStatus::Draft],
            'confirmed' => [DocumentStatus::Confirmed],
            'paid, never sealed' => [DocumentStatus::Paid],
            'posted, never sealed' => [DocumentStatus::Posted],
        ];
    }

    #[DataProvider('unsealedStatusProvider')]
    public function test_no_unsealed_status_puts_a_lifecycle_word_on_the_proforma(DocumentStatus $status): void
    {
        $this->app->setLocale('en');
        $invoice = $this->atStatus($this->unpostedInvoice(), $status);

        $html = $this->renderHtml($invoice);
        $scannable = $this->scannable($html);

        $this->assertNoForbiddenToken($scannable, "{$status->value} invoice HTML");
        $this->assertNoForbiddenToken($this->renderPdfText($invoice), "{$status->value} invoice PDF text");

        // The badge MARKUP is gone — asserted on the rendered page, not the raw
        // file: `layouts/document.blade.php`'s stylesheet declares `.status-badge`
        // and `.status-posted` for the other seven templates, and a CSS class name
        // is not a statement about this document.
        $this->assertStringNotContainsString(
            'status-badge',
            $scannable,
            'a proforma states no lifecycle status: the badge markup itself must be gone',
        );

        // …and so is the WORD, which the token scan cannot catch for `Paid` or
        // `Draft`. The badge is upper-cased into the PDF and drawn on its own line,
        // so exact line membership is the honest check — a substring search would
        // report `238,000 DT` as containing `38,000 DT`.
        $lines = $this->pdfLines($this->renderPdfText($invoice));
        foreach (DocumentStatus::cases() as $case) {
            $this->assertNotContains(
                mb_strtoupper(ucfirst(str_replace('_', ' ', $case->value))),
                $lines,
                "the {$case->value} badge text must not be drawn on a proforma",
            );
        }

        $this->assertStringContainsString(__('documents.proforma.title'), $html);
    }

    /**
     * FIX ROUND r1 / gate F-2 — ORCHESTRATOR SPEC RULING (r11.2 amendment, owner
     * OQ-75 default): the proforma prints GROSS line figures.
     *
     * r1 printed NET line amounts under a GROSS `Estimated total`. The page did
     * not reconcile on its own face, and the difference — one subtraction — was
     * exactly the VAT the lane had removed. `totals.blade.php` stated the rule
     * ("a reader who can subtract has the tax amount back") while the table above
     * it supplied the net figure anyway.
     *
     * The fixture is deliberately TWO lines with different amounts, one carrying a
     * persisted `document_lines.tax_amount` and one carrying only a `tax_rate`, so
     * the reconciliation is a real sum over both resolution paths and not an
     * identity on a single row.
     */
    public function test_proforma_line_figures_are_gross_and_sum_to_the_estimated_total(): void
    {
        $this->app->setLocale('en');
        $invoice = $this->twoLineUnpostedInvoice();

        $html = $this->renderHtml($invoice);
        $money = $this->moneyFormatter($invoice);

        // The amount column, read off the rendered items table, in order:
        // unit price then line amount, per row (the rate column is gone).
        $this->assertSame(
            [
                $money('119.000'), $money('238.000'),
                $money('59.500'), $money('59.500'),
            ],
            $this->itemsTableRightCells($html),
            'every printed line figure must be tax-inclusive',
        );

        // Σ printed line amounts == the estimated total, at the VALUE level.
        $this->assertSame(
            (string) $invoice->total,
            bcadd('238.000', '59.500', 3),
            'the fixture itself must reconcile, or the assertion below is vacuous',
        );
        $this->assertStringContainsString($money('297.500'), $html);
    }

    /**
     * The security property F-2 actually buys, stated as an absence: the VAT is
     * not on the page, and neither is any figure it could be recovered from.
     * `total − net` cannot be formed by a reader who never sees `net`.
     */
    public function test_no_figure_on_a_proforma_yields_the_vat_by_subtraction(): void
    {
        $this->app->setLocale('en');
        $invoice = $this->twoLineUnpostedInvoice();

        $rendered = $this->renderHtml($invoice);
        $money = $this->moneyFormatter($invoice);

        // EXACT FIGURES, never substrings: `238,000 DT` contains `38,000 DT`, so a
        // substring search would report the VAT as printed when it is not. The set
        // of money figures the page actually prints is the items table's right
        // cells plus the totals table's cells; the PDF draws each on its own line.
        $printedInHtml = array_merge(
            $this->itemsTableRightCells($rendered),
            $this->totalsTableCells($rendered),
        );
        $printedInPdf = $this->pdfLines($this->renderPdfText($invoice));

        $this->assertSame(
            [$money('297.500')],
            $this->totalsTableCells($rendered),
            'the totals box prints one figure and it is the estimated total',
        );

        foreach ([
            '47.500' => 'the VAT total itself',
            '250.000' => 'the net subtotal — estimated total MINUS this IS the VAT',
            '200.000' => 'line 1 net amount — its gross counterpart is printed instead',
            '100.000' => 'line 1 net unit price',
            '38.000' => 'line 1 VAT',
            '9.500' => 'line 2 VAT',
        ] as $amount => $why) {
            $this->assertNotContains($money($amount), $printedInHtml, "the HTML must not print {$why}");
            $this->assertNotContains($money($amount), $printedInPdf, "the PDF must not print {$why}");
        }
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

    /**
     * The `$formatMoney` closure the templates themselves use, so the expected
     * strings are produced by the production formatter and not by a test-local
     * imitation of it.
     *
     * @return \Closure(string): string
     */
    private function moneyFormatter(Document $document): \Closure
    {
        /** @var DocumentPdfService $service */
        $service = $this->app->make(DocumentPdfService::class);
        /** @var \Closure(string|float|null): string $formatter */
        $formatter = $service->viewDataFor($document)['formatMoney'];

        return static fn (string $amount): string => $formatter($amount);
    }

    /**
     * Every right-aligned cell of the items table, in document order — i.e. the
     * unit price and the line amount of each row, which with the rate column gone
     * is the complete set of money figures the table prints.
     *
     * @return list<string>
     */
    private function itemsTableRightCells(string $html): array
    {
        if (preg_match('#<table class="items-table">(.*?)</table>#s', $html, $table) !== 1) {
            self::fail('no items table was rendered');
        }

        preg_match_all('#<td class="right">(.*?)</td>#s', $table[1], $cells);

        return array_map(static fn (string $cell): string => trim($cell), $cells[1]);
    }

    /**
     * Every money cell of the totals box.
     *
     * @return list<string>
     */
    private function totalsTableCells(string $html): array
    {
        if (preg_match('#<table class="totals-table">(.*?)</table>#s', $html, $table) !== 1) {
            self::fail('no totals table was rendered');
        }

        preg_match_all('#<td[^>]*>(.*?)</td>#s', $table[1], $cells);

        $labels = [__('documents.proforma.estimated_total')];

        return array_values(array_filter(
            array_map(static fn (string $cell): string => trim($cell), $cells[1]),
            static fn (string $cell): bool => ! in_array($cell, $labels, true),
        ));
    }

    /**
     * The PDF's drawn text, one trimmed entry per glyph run — which is one per
     * table cell, so exact membership is meaningful.
     *
     * @return list<string>
     */
    private function pdfLines(string $text): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), explode("\n", $text)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function atStatus(Document $document, DocumentStatus $status): Document
    {
        $document->forceFill(['status' => $status])->save();
        $document->refresh();

        return $document;
    }

    /**
     * Two lines, different amounts, and the two ways a line's tax is knowable:
     * line 1 carries only `tax_rate` (`document_lines.tax_amount` is nullable and
     * NULL on older rows), line 2 carries a persisted `tax_amount`.
     */
    private function twoLineUnpostedInvoice(): Document
    {
        $invoice = $this->dpConfirmedInvoice([
            $this->dpPhysicalLine('2.0000', '100.000'),
            $this->dpPhysicalLine('1.0000', '50.000'),
        ]);

        $this->dpCompany->forceFill([
            'vat_number' => 'TN-VAT-1234567',
            'phone' => null,
            'email' => null,
            'registration_number' => null,
        ])->save();

        $lines = $invoice->lines->sortBy('line_number')->values();
        $lines->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => null])->save();
        $lines->skip(1)->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => '9.500'])->save();

        $invoice->forceFill([
            'document_number' => 'INV-PROFORMA-0002',
            'document_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'subtotal' => '250.000',
            'line_tax_amount' => '47.500',
            'stamp_duty_amount' => '0.000',
            'discount_amount' => '0.000',
            'tax_amount' => '47.500',
            'total' => '297.500',
            'balance_due' => '297.500',
            'notes' => null,
        ])->save();
        $invoice->refresh();

        return $invoice;
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
