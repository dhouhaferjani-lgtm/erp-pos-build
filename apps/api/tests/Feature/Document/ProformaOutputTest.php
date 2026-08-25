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

    /**
     * The three rows of `discountedUnpostedInvoice()`, as PRINTED: unit price,
     * quantity, line amount. Every one divides exactly, so `unit × qty == amount`
     * is asserted without a tolerance — see the residual note in the handback for
     * the quantities that do not divide.
     *
     * @var array<string, array{0: numeric-string, 1: numeric-string, 2: numeric-string}>
     */
    private const DISCOUNTED_ROWS = [
        'qty 2, persisted tax ≠ rate × net' => ['118.240', '2.0000', '236.480'],
        'qty 1' => ['59.120', '1.0000', '59.120'],
        'qty 4, line-level discount' => ['26.604', '4.0000', '106.416'],
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

    /**
     * FIX ROUND r3 / conventions F-C2 + fiscal F-12 [BLOCKING].
     *
     * Every R-8 test built an Invoice, and the credit note re-implemented the three
     * conditional rows in its own totals block — so the duplicated markup on one of
     * the exactly two document types this lane exists for was rendered by nothing.
     * The markup is now ONE shared partial, and these tests run over both types so a
     * divergence cannot come back the way it came.
     *
     * @return array<string, array{0: DocumentType}>
     */
    public static function fiscalTypeProvider(): array
    {
        return [
            'invoice' => [DocumentType::Invoice],
            'credit note' => [DocumentType::CreditNote],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: DocumentType}>
     */
    public static function localeAndFiscalTypeProvider(): array
    {
        $cases = [];

        foreach (self::localeProvider() as $localeLabel => [$locale]) {
            foreach (self::fiscalTypeProvider() as $typeLabel => [$type]) {
                $cases["{$localeLabel} {$typeLabel}"] = [$locale, $type];
            }
        }

        return $cases;
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

    /**
     * FIX ROUND r2 / gate F-7 + F-8 [BLOCKING] — EVERY ROW CLOSES ON ITS OWN FACE.
     *
     * r1 fixed the page and broke the row. `unitPrice()` was rate-derived while
     * `lineAmount()` took the persisted, post-document-discount `tax_amount`, and
     * the two bases cannot agree once a document-level discount exists: the tax
     * engine prorates that discount into every rate bucket
     * (`TaxCalculationService:171-183`) and a rate-derived unit price knows nothing
     * about it. The gate's probe printed `Qty 1,00 · Unit Price 59,500 · Amount
     * 59,120` — the customer-facing document contradicting itself on one line, which
     * is r1's F-2 defect one level down. The population is real:
     * `POSAccountChargeDraftService:62,164` writes a document-level discount.
     *
     * Both printed figures now come from ONE basis: the gross line amount is built
     * from the persisted post-discount facts, and the unit price is that amount
     * divided by the quantity.
     *
     * THIS FIXTURE ALSO PINS F-8, which r1 left unpinned — a rewrite to either
     * forbidden derivation passed all 24 tests, because line 1 had a NULL
     * `tax_amount` (forcing the rate path) and line 2 had `quantity = 1` (where
     * every derivation coincides). Here:
     *   - line 1 has quantity 2 AND a persisted `tax_amount` (36.480) that is NOT
     *     `rate × line_total` (38.000) ⇒ a rate-derived unit price prints 119.000
     *     and 119.000 × 2 = 238.000 ≠ 236.480. RED.
     *   - line 3 carries a LINE-level discount, so `line_total ≠ unit_price × qty`
     *     ⇒ the `unit_price + tax_amount ÷ qty` derivation prints 29.104 and
     *     29.104 × 4 = 116.416 ≠ 106.416. RED.
     * One fixture, both forbidden derivations falsified.
     */
    #[DataProvider('fiscalTypeProvider')]
    public function test_every_proforma_row_closes_on_its_own_face(DocumentType $type): void
    {
        $this->app->setLocale('en');
        $invoice = $this->discountedUnposted($type);

        $money = $this->moneyFormatter($invoice);
        $printed = $this->itemsTableRightCells($this->renderHtml($invoice));

        $expected = [];

        foreach (self::DISCOUNTED_ROWS as $label => [$unitPrice, $quantity, $lineAmount]) {
            // The identity the customer performs with a calculator, at the
            // currency's own scale. If this ever needs a tolerance, the two
            // printed figures have stopped coming from one basis.
            $this->assertSame(
                $lineAmount,
                bcmul($unitPrice, $quantity, 3),
                "row {$label}: printed unit price × quantity must be the printed amount",
            );

            $expected[] = $money($unitPrice);
            $expected[] = $money($lineAmount);
        }

        $this->assertSame($expected, $printed, 'the page must print exactly those figures');
    }

    /**
     * FIX ROUND r2 / gate r2 §3 ruling on R-8 — the page reconciles WITH a stamp
     * duty and a document-level discount, and the reconciling row is DERIVED.
     *
     * `Stamp duty` is the stored statutory figure. The discount row is computed as
     * `Σ printed gross lines + stamp − total`, never assembled from
     * `discount_amount`: the two coincide only when every line carries a persisted
     * `tax_amount`, and the NULL-`tax_amount` fallback taxes the PRE-discount net,
     * so an assembled figure would leave a silent remainder on a legacy row. A
     * derived row reconciles by construction on every shape.
     *
     * Neither word is a tax word. A document-level discount is not a tax mention,
     * and the TN timbre is a *droit de timbre* under the Code des droits
     * d'enregistrement et de timbre — naming a separate duty is not mentioning TVA,
     * which is the only thing Art. 18 attaches liability to.
     */
    #[DataProvider('fiscalTypeProvider')]
    public function test_the_proforma_totals_box_reconciles_with_a_stamp_duty_and_a_discount(DocumentType $type): void
    {
        $this->app->setLocale('en');
        $invoice = $this->discountedUnposted($type);

        $money = $this->moneyFormatter($invoice);

        $this->assertSame(
            [$money('1.000'), '-'.$money('10.000'), $money('393.016')],
            $this->totalsTableCells($this->renderHtml($invoice)),
            'stamp duty, then the derived discount, then the estimated total',
        );

        $grossLines = '0.000';
        foreach (self::DISCOUNTED_ROWS as [, , $lineAmount]) {
            $grossLines = bcadd($grossLines, $lineAmount, 3);
        }

        $this->assertSame('402.016', $grossLines);
        $this->assertSame(
            (string) $invoice->total,
            bcsub(bcadd($grossLines, '1.000', 3), '10.000', 3),
            'Σ printed gross lines + printed stamp duty − printed discount == the estimated total',
        );
    }

    #[DataProvider('localeAndFiscalTypeProvider')]
    public function test_a_proforma_with_a_stamp_duty_and_a_discount_stays_clean(string $locale, DocumentType $type): void
    {
        $this->app->setLocale($locale);
        $invoice = $this->discountedUnposted($type);

        $this->assertNoForbiddenToken(
            $this->scannable($this->renderHtml($invoice)),
            "discounted {$type->value} HTML in {$locale}",
        );
        $this->assertNoForbiddenToken(
            $this->renderPdfText($invoice),
            "discounted {$type->value} PDF text in {$locale}",
        );

        $this->assertStringContainsString(__('documents.proforma.stamp_duty'), $this->renderHtml($invoice));
        $this->assertStringContainsString(__('documents.proforma.discount'), $this->renderHtml($invoice));
    }

    /**
     * The duty and discount rows must not hand back what the gross lines were
     * hiding. Exact membership again — `236,480 DT` contains `36,480 DT`.
     */
    #[DataProvider('fiscalTypeProvider')]
    public function test_the_duty_and_discount_rows_do_not_reveal_the_vat(DocumentType $type): void
    {
        $this->app->setLocale('en');
        $invoice = $this->discountedUnposted($type);

        $rendered = $this->renderHtml($invoice);
        $money = $this->moneyFormatter($invoice);

        $printedInHtml = array_merge(
            $this->itemsTableRightCells($rendered),
            $this->totalsTableCells($rendered),
        );
        $printedInPdf = $this->pdfLines($this->renderPdfText($invoice));

        foreach ([
            '62.016' => 'the VAT total',
            '340.000' => 'the net subtotal',
            '330.000' => 'the discounted net',
            '200.000' => 'line 1 net amount',
            '100.000' => 'line 1 net unit price',
            '50.000' => 'line 2 net amount and unit price',
            '90.000' => 'line 3 net amount',
            '25.000' => 'line 3 net unit price',
            '36.480' => 'line 1 VAT',
            '9.120' => 'line 2 VAT',
            '16.416' => 'line 3 VAT',
        ] as $amount => $why) {
            $this->assertNotContains($money($amount), $printedInHtml, "the HTML must not print {$why}");
            $this->assertNotContains($money($amount), $printedInPdf, "the PDF must not print {$why}");
        }
    }

    /**
     * The other sign. A derived reconciling row has to close the page when the
     * residual runs the other way — a legacy row whose NULL `tax_amount` made the
     * fallback tax the PRE-discount net is exactly how that happens — and calling
     * an increase a "Discount" would be a lie. It gets its own neutral label.
     */
    #[DataProvider('fiscalTypeProvider')]
    public function test_a_residual_that_runs_the_other_way_is_not_called_a_discount(DocumentType $type): void
    {
        $this->app->setLocale('en');
        $invoice = $this->surchargedUnposted($type);

        $money = $this->moneyFormatter($invoice);
        $cells = $this->totalsTableCells($this->renderHtml($invoice));

        $this->assertSame([$money('5.000'), $money('243.000')], $cells);
        $this->assertSame(
            (string) $invoice->total,
            bcadd('238.000', '5.000', 3),
            'Σ printed gross lines + the printed adjustment == the estimated total',
        );
    }

    /**
     * FIX ROUND r2 — the reconciling row is DERIVED, and this is the shape that
     * proves it, because on every other fixture "derived" and "assembled from
     * `stamp − discount`" give the same number.
     *
     * One line with a NULL `document_lines.tax_amount` — every row written before
     * the tax engine existed — under a document-level discount. The fallback taxes
     * the PRE-discount net (19% of 200.000 = 38.000), while the document's own
     * `line_tax_amount` was taken on the discounted base (19% of 190.000 = 36.100).
     * So Σ printed gross = 238.000 against a stored total of 226.100:
     *
     *   derived   : 238.000 − 226.100 = 11.900  → the page closes
     *   assembled : stamp − discount  = 10.000  → 238.000 − 10.000 = 228.000, a
     *               silent 1.900 remainder in front of the customer
     *
     * This is gate r2 §3's first condition, and without this test it would be
     * unpinned exactly the way F-8's invariant was.
     */
    public function test_the_reconciling_row_is_derived_and_not_assembled_from_the_stored_discount(): void
    {
        $this->app->setLocale('en');
        $invoice = $this->legacyTaxRowUnposted();

        $money = $this->moneyFormatter($invoice);

        $this->assertSame(
            ['-'.$money('11.900'), $money('226.100')],
            $this->totalsTableCells($this->renderHtml($invoice)),
            'the discount row must be total − Σ gross lines, not the stored discount_amount',
        );
        $this->assertSame(
            (string) $invoice->total,
            bcsub('238.000', '11.900', 3),
            'and the page closes on the figures it actually prints',
        );
    }

    /**
     * FIX ROUND r3 / conventions F-C3 [BLOCKING] — a proforma credit note may not
     * tell the customer it reduces their balance.
     *
     * `templates/credit_note.blade.php` closed with an ungated
     * "This credit note reduces your balance by the amount shown above." — printed
     * under a banner reading "Proforma — non-fiscal document … it confers no right
     * of deduction", on a page from which this lane deliberately deleted the Paid
     * and Balance Due rows because a proforma is not a statement of account. The
     * page asserted a balance effect the document does not have, and called itself
     * a *credit note*, the definitive noun, in the one place the lane took care not
     * to. It was also a non-dotted key with no JSON lang file, so a French or
     * Tunisian customer read it in English.
     *
     * Now: absent on a proforma, and a dotted key in three locales on a definitive
     * credit note.
     */
    public function test_a_proforma_credit_note_does_not_claim_it_reduces_your_balance(): void
    {
        $this->app->setLocale('en');
        $proforma = $this->unpostedCreditNote();

        $definitiveSentence = 'This credit note reduces your balance by the amount shown above.';

        $this->assertStringNotContainsString($definitiveSentence, $this->renderHtml($proforma));
        $this->assertStringNotContainsString(
            __('documents.credit_note.balance_note'),
            $this->renderHtml($proforma),
        );
        $this->assertStringNotContainsString('reduces your balance', $this->renderPdfText($proforma));
    }

    /**
     * …and the definitive credit note still says it, byte for byte. The key moved
     * from a non-dotted literal to `documents.credit_note.balance_note`, and the
     * English value is unchanged precisely so the posted snapshots stay identical.
     */
    public function test_a_definitive_credit_note_still_states_the_balance_effect(): void
    {
        $this->app->setLocale('en');

        $this->assertSame(
            'This credit note reduces your balance by the amount shown above.',
            __('documents.credit_note.balance_note'),
        );
        $this->assertStringContainsString(
            __('documents.credit_note.balance_note'),
            $this->renderHtml($this->postedCreditNote()),
        );
    }

    /**
     * FIX ROUND r3 / conventions F-C1 [MAJOR] — the three-locale claim was pinned
     * TAUTOLOGICALLY.
     *
     * `assertStringContainsString(__('documents.proforma.title'), $html)` resolves
     * both sides through the same Translator at the same locale. Delete the
     * `proforma` block from `lang/ar/documents.php` and Laravel falls back per key
     * to `en` — for the blade AND for the assertion — so the test passes on a page
     * that renders English to an Arabic tenant. If the key vanished from `en` too,
     * both sides get the literal key string back and it STILL passes.
     *
     * These are the strings themselves. `DocumentsProformaLangParityTest` is the
     * other half: it pins the key sets and placeholder sets across the three files.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function localeLiteralProvider(): array
    {
        return [
            'french' => ['fr', [
                'title' => 'Proforma — document non fiscal',
                'estimated_total' => 'Total estimé',
                'stamp_duty' => 'Droit de timbre',
                'discount' => 'Remise',
            ]],
            'arabic' => ['ar', [
                'title' => 'مستند مبدئي — غير ضريبي',
                'estimated_total' => 'المجموع التقديري',
                'stamp_duty' => 'معلوم الطابع',
                'discount' => 'تخفيض',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('localeLiteralProvider')]
    public function test_the_proforma_renders_the_literal_strings_of_its_locale(string $locale, array $expected): void
    {
        $this->app->setLocale($locale);
        $html = $this->renderHtml($this->discountedUnposted());

        foreach ($expected as $key => $literal) {
            $this->assertStringContainsString(
                $literal,
                $html,
                "documents.proforma.{$key} must render its own {$locale} string, not a fallback",
            );
        }
    }

    /**
     * FIX ROUND r3 / fiscal F-11 — the drift bound, measured rather than reassured.
     *
     * r2's docblock and residual R-10 claimed `printed unit × qty` differs from the
     * printed amount "by less than half a currency unit per line item". That is
     * wrong: the unit price is rounded once and then multiplied by the quantity, so
     * the error is LINEAR IN QUANTITY — the true bound is `qty × 0.5 × 10^-scale`.
     * At 10 000 units of a 0.333 part it is 2.700 DT, five times the claimed
     * ceiling and plainly visible on the page.
     *
     * Not a fiscal exposure: the printed AMOUNT is authoritative, the estimated
     * total sums the amounts, and the box still closes — all three asserted here.
     * The scale stays at the currency's own (gate r3 ruling R-10: `unit × qty ==
     * amount` is unattainable at ANY finite scale for a non-terminating quotient,
     * and a scale-5 unit price would be the only figure on the page off convention).
     * What changes is that the trade-off is now characterised and pinned instead of
     * being described by a comforting sentence.
     */
    public function test_a_bulk_non_dividing_row_drifts_within_the_stated_bound(): void
    {
        $this->app->setLocale('en');
        $invoice = $this->bulkNonDividingUnpostedInvoice();

        $money = $this->moneyFormatter($invoice);

        $this->assertSame(
            [$money('0.396'), $money('3962.700')],
            $this->itemsTableRightCells($this->renderHtml($invoice)),
            'the unit price is the rounded quotient; the AMOUNT is authoritative',
        );

        // The amount is what the totals box sums, so the page still closes.
        $this->assertSame(
            [$money('3962.700')],
            $this->totalsTableCells($this->renderHtml($invoice)),
        );

        $quantity = '10000.0000';
        $drift = bcsub(bcmul('0.396', $quantity, 3), '3962.700', 3);
        $bound = bcmul($quantity, '0.0005', 4);   // qty × 0.5 × 10^-scale, scale = 3

        $this->assertSame('-2.700', $drift, 'the drift is real and this is its size');
        $this->assertSame('5.0000', $bound);
        $this->assertLessThanOrEqual(
            0,
            bccomp(bcmul($drift, '-1', 3), $bound, 4),
            'the drift must stay within qty × 0.5 × 10^-scale',
        );
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

        $labels = [
            __('documents.proforma.estimated_total'),
            __('documents.proforma.stamp_duty'),
            __('documents.proforma.discount'),
            __('documents.proforma.adjustment'),
        ];

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
    /**
     * The shape gate r2 F-7 was found on: a document-level discount prorated into
     * the line taxes by the tax engine, plus the TN timbre, plus a line-level
     * discount on one row.
     *
     *   L1  qty 2  net 200.000  VAT 36.480 (persisted; rate × net would be 38.000)
     *   L2  qty 1  net  50.000  VAT  9.120 (persisted)
     *   L3  qty 4  net  90.000  VAT 16.416 (persisted; line discount 10.000)
     *   subtotal 340.000 − discount 10.000 + line VAT 62.016 + stamp 1.000 = 393.016
     *   Σ gross lines 402.016; 402.016 + 1.000 − 10.000 = 393.016
     *
     * FIX ROUND r3 / fiscal F-13 — THESE LINE TAXES ARE SYNTHETIC, chosen so every
     * row divides its quantity exactly and the row-closure assertion can be exact
     * with no tolerance. They are NOT the tax engine's proration of this fixture's
     * own `discount_amount`: 36.480 + 9.120 + 16.416 = 62.016 implies a taxed base
     * of 326.400 (a 4% reduction), while the stated discount is 10.000 on 340.000
     * (2.94%). The document is internally consistent
     * (340.000 − 10.000 + 62.016 + 1.000 = 393.016) and every assertion is over what
     * is PRINTED, so nothing is weakened — but a reader who tries to reconcile the
     * figures against `TaxCalculationService:171-183` will not be able to, and
     * should not try. The SHAPE (persisted post-discount line taxes that are not
     * `rate × line_total`) is what the tests need; the exact proration is not.
     */
    private function discountedUnposted(DocumentType $type = DocumentType::Invoice): Document
    {
        $invoice = $this->confirmedOfType($type, [
            $this->dpPhysicalLine('2.0000', '100.000'),
            $this->dpPhysicalLine('1.0000', '50.000'),
            $this->dpPhysicalLine('4.0000', '25.000'),
        ]);

        $this->dpCompany->forceFill([
            'vat_number' => 'TN-VAT-1234567',
            'phone' => null,
            'email' => null,
            'registration_number' => null,
        ])->save();

        $lines = $invoice->lines->sortBy('line_number')->values();
        $lines->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => '36.480'])->save();
        $lines->skip(1)->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => '9.120'])->save();
        $lines->skip(2)->firstOrFail()->forceFill([
            'tax_rate' => '19.00',
            'tax_amount' => '16.416',
            'discount_amount' => '10.000',
            'line_total' => '90.000',
        ])->save();

        $invoice->forceFill([
            'document_number' => 'INV-PROFORMA-0003',
            'document_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'subtotal' => '340.000',
            'discount_amount' => '10.000',
            'line_tax_amount' => '62.016',
            'stamp_duty_amount' => '1.000',
            'tax_amount' => '63.016',
            'total' => '393.016',
            'balance_due' => '393.016',
            'notes' => null,
        ])->save();
        $invoice->refresh();

        return $invoice;
    }

    /**
     * One line, gross 238.000, and a stored total 5.000 ABOVE it with no stamp and
     * no discount — the residual runs the other way.
     */
    /**
     * A pre-tax-engine row (`document_lines.tax_amount` NULL) under a document-level
     * discount — the shape that separates a derived reconciling row from an
     * assembled one.
     */
    private function legacyTaxRowUnposted(DocumentType $type = DocumentType::Invoice): Document
    {
        $invoice = $this->deterministic(
            $this->confirmedOfType($type, [$this->dpPhysicalLine()]),
            'PROFORMA-0005',
        );

        $invoice->lines->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => null])->save();
        $invoice->forceFill([
            'subtotal' => '200.000',
            'discount_amount' => '10.000',
            'line_tax_amount' => '36.100',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '36.100',
            'total' => '226.100',
            'balance_due' => '226.100',
        ])->save();
        $invoice->refresh();

        return $invoice;
    }

    private function surchargedUnposted(DocumentType $type = DocumentType::Invoice): Document
    {
        $invoice = $this->deterministic(
            $this->confirmedOfType($type, [$this->dpPhysicalLine()]),
            'PROFORMA-0004',
        );

        $invoice->lines->firstOrFail()->forceFill(['tax_rate' => '19.00', 'tax_amount' => '38.000'])->save();
        $invoice->forceFill([
            'stamp_duty_amount' => '0.000',
            'discount_amount' => '0.000',
            'total' => '243.000',
            'balance_due' => '243.000',
        ])->save();
        $invoice->refresh();

        return $invoice;
    }

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

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    /**
     * 10 000 units of a 0.333 part at 19% — a quantity that does not divide its
     * gross line amount, so the rounded unit price multiplies back short.
     *
     *   net 3330.000 + VAT 632.700 = gross 3962.700 ; 3962.700 ÷ 10000 = 0.39627
     *   printed unit 0.396 ; 0.396 × 10000 = 3960.000 ; drift −2.700
     *   bound qty × 0.5 × 10^-3 = 5.000
     *
     * No stamp and no discount, so the totals box is the estimated total alone and
     * the reconciliation is over the AMOUNT column, which is exact.
     */
    private function bulkNonDividingUnpostedInvoice(): Document
    {
        $invoice = $this->deterministic(
            $this->dpConfirmedInvoice([$this->dpPhysicalLine('10000.0000', '0.333')]),
            'PROFORMA-0006',
        );

        $invoice->lines->firstOrFail()->forceFill([
            'tax_rate' => '19.00',
            'tax_amount' => '632.700',
            'line_total' => '3330.000',
        ])->save();

        $invoice->forceFill([
            'subtotal' => '3330.000',
            'discount_amount' => '0.000',
            'line_tax_amount' => '632.700',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '632.700',
            'total' => '3962.700',
            'balance_due' => '3962.700',
        ])->save();
        $invoice->refresh();

        return $invoice;
    }

    /**
     * The same fixture over either fiscal type — conventions F-C2 / fiscal F-12.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function confirmedOfType(DocumentType $type, array $lines): Document
    {
        return $type === DocumentType::CreditNote
            ? $this->dpConfirmedCreditNote($lines)
            : $this->dpConfirmedInvoice($lines);
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
