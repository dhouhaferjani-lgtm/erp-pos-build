<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Application\Services\DocumentPdfService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * C-F0 census — the proforma gate is a property of the TEMPLATE TREE, not of one
 * template.
 *
 * `ProformaOutputTest` proves the invoice and the credit note are clean today.
 * This class proves the next template cannot quietly be dirty. Three things can
 * reintroduce F-95:
 *
 *   1. A NEW template file under `documents/templates` for a fiscal type.
 *   2. A COUNTRY template — `DocumentPdfService::resolveTemplate()` prefers
 *      `documents.country.{code}.{type}` over `documents.templates.{type}`, so the
 *      first country invoice template silently replaces the gated one (this is
 *      N-6 fiscal gate F-5's coverage caveat, closed here for this lane).
 *   3. A shared COMPONENT that emits a tax mention without consulting the flag —
 *      `line_items`, `totals` and `parties` are included by eight templates, and
 *      `layouts/document` wraps all of them.
 *
 * The census is therefore over every blade file in `resources/views/documents`,
 * and every file that emits a tax mention must either consult `isProforma` or be
 * named in the exemption map below with the reason it can never be a proforma.
 */
final class ProformaTemplateCensusTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    /**
     * Anything that puts a tax mention on the page.
     *
     * FIX ROUND r1 / gate F-4 — r1's regex was `__('(Tax|VAT|Tax ID)')|tax_amount|
     * tax_rate|tax_id|vat_number|showTax`, which a new template evades by writing
     * `__('TVA')`, a bare `TTC` / `HT` literal, `stamp_duty_amount` (the TN timbre,
     * a document-level tax that lives outside every per-line field named above),
     * `line_tax_amount`, or by rendering the API's `document_tax_details` /
     * `taxBreakdown` shape. All of them are matched now. `\bHT\b` is anchored and
     * case-SENSITIVE for the same reason it is in ProformaOutputTest: unanchored,
     * it matches `height` and `right` in every style attribute in the tree.
     */
    private const TAX_EMISSION = '/__\(\'(Tax|VAT|TVA|Tax ID|TTC|HT)\'\)|\bTTC\b|\bHT\b|tax_amount|tax_rate|tax_id|vat_number|showTax|stamp_duty_amount|line_tax_amount|document_tax_details|taxBreakdown|tax_details/';

    /**
     * Blade files that emit a tax mention and are NOT gated, each with the reason
     * it can never render a proforma. A file that leaves this map and stops
     * consulting `isProforma` fails the census.
     *
     * @var array<string, string>
     */
    private const UNGATED_BY_DESIGN = [
        'templates/purchase_order.blade.php' => 'PurchaseOrder is not a fiscal document type (DocumentPostingService::FISCAL_DOCUMENT_TYPES) — it is the buyer\'s own commitment, never issued to a customer as a VAT-bearing paper.',
        'templates/purchase_rfq.blade.php' => 'PurchaseQuoteRequest is not a fiscal document type; it passes showTax => false already.',
        'templates/quote.blade.php' => 'Quote is not a fiscal document type — a quotation is already non-definitive by nature and carries no posting.',
        'templates/sales_order.blade.php' => 'SalesOrder is not a fiscal document type.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures('TN');
    }

    public function test_only_invoice_and_credit_note_are_fiscal_document_types(): void
    {
        $this->assertSame(
            [DocumentType::Invoice, DocumentType::CreditNote],
            DocumentPostingService::getFiscalDocumentTypes(),
            'the exemption map below is derived from this list',
        );
    }

    public function test_every_blade_that_emits_a_tax_mention_consults_the_proforma_flag(): void
    {
        $ungated = [];

        foreach ($this->bladeFiles() as $relative => $absolute) {
            $contents = (string) file_get_contents($absolute);

            if (preg_match(self::TAX_EMISSION, $contents) !== 1) {
                continue;
            }

            if ($this->consultsTheFlagInCode($contents)) {
                continue;
            }

            if (array_key_exists($relative, self::UNGATED_BY_DESIGN)) {
                continue;
            }

            $ungated[] = $relative;
        }

        $this->assertSame(
            [],
            $ungated,
            'these blade files put a tax mention on the page without consulting $isProforma: '
                .implode(', ', $ungated),
        );
    }

    /**
     * The exemption map may not rot in the other direction either: an entry for a
     * file that no longer exists, or that no longer emits a tax mention, hides the
     * next real one.
     */
    public function test_no_exemption_is_stale(): void
    {
        $files = $this->bladeFiles();

        foreach (self::UNGATED_BY_DESIGN as $relative => $reason) {
            $this->assertArrayHasKey($relative, $files, "exempted blade {$relative} no longer exists");
            $this->assertMatchesRegularExpression(
                self::TAX_EMISSION,
                (string) file_get_contents($files[$relative]),
                "exempted blade {$relative} no longer emits a tax mention — drop the exemption",
            );
            $this->assertNotSame('', $reason);
        }
    }

    /**
     * N-6 fiscal gate F-5's caveat, as an executable guard: no country override
     * exists for a fiscal type, and if one is ever added it must be gated.
     */
    public function test_no_ungated_country_template_shadows_a_fiscal_template(): void
    {
        foreach (DocumentPostingService::getFiscalDocumentTypes() as $type) {
            foreach (glob(resource_path('views/documents/country/*/'.$type->value.'.blade.php')) ?: [] as $override) {
                $this->assertTrue(
                    $this->consultsTheFlagInCode((string) file_get_contents($override)),
                    sprintf(
                        'country template %s shadows the gated documents.templates.%s and must carry the proforma gate itself',
                        $override,
                        $type->value,
                    ),
                );
            }
        }

        $this->assertSame(
            [],
            glob(resource_path('views/documents/country/*/invoice.blade.php')) ?: [],
            'the first country invoice template is a deliberate decision, not a drive-by: gate it, then record it here',
        );
    }

    public function test_the_pdf_view_data_always_carries_the_proforma_flag(): void
    {
        /** @var DocumentPdfService $service */
        $service = $this->app->make(DocumentPdfService::class);

        foreach ([$this->invoice(), $this->salesOrder()] as $document) {
            $this->assertArrayHasKey(
                'isProforma',
                $service->viewDataFor($document),
                'the blade fallbacks are a belt, not the braces — the service must always decide',
            );
        }
    }

    /**
     * No collateral damage: a sales order is not a fiscal document and keeps its
     * tax column exactly as before.
     */
    public function test_a_non_fiscal_document_type_still_renders_its_tax_column(): void
    {
        $this->app->setLocale('en');

        /** @var DocumentPdfService $service */
        $service = $this->app->make(DocumentPdfService::class);
        $order = $this->salesOrder();

        $html = view('documents.templates.sales_order', $service->viewDataFor($order))->render();

        $this->assertStringContainsString('Tax', $html);
        $this->assertFalse($service->viewDataFor($order)['isProforma']);
    }

    /**
     * Does this blade consult `$isProforma` in CODE — not in a comment?
     *
     * FIX ROUND r1 / gate F-4. r1 asked `str_contains($contents, 'isProforma')`,
     * which the docblock explaining the gate satisfies just as well as the gate
     * does. A template whose gate was deleted but whose comment survived — the
     * ordinary shape of a bad merge — passed. So: strip Blade comments and PHP
     * comments first, then look for the flag only inside the constructs Blade
     * actually evaluates — a parenthesised directive, an `@php … @endphp` block,
     * or an echo.
     */
    private function consultsTheFlagInCode(string $contents): bool
    {
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        $evaluated = '';

        foreach ([
            '/@php\b(?!\s*\()(.*?)@endphp/s',                 // @php … @endphp block
            '/@(?:if|elseif|unless|include|php)\s*\((.*)\)\s*$/m',  // parenthesised directive
            '/\{\{(.*?)\}\}/s',                                // echo
            '/@include\(\s*\'[^\']+\',\s*\[(.*?)\]\s*\)/s',      // multi-line @include array
        ] as $pattern) {
            if (preg_match_all($pattern, $code, $matches) > 0) {
                $evaluated .= implode("\n", $matches[1]);
            }
        }

        return str_contains($evaluated, 'isProforma');
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views/documents');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $files[str_replace($root.'/', '', $file->getPathname())] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * The census's own falsification test (F-4): a blade whose ONLY mention of the
     * flag is a comment must NOT count as gated.
     */
    public function test_a_comment_does_not_satisfy_the_gate_check(): void
    {
        $this->assertFalse(
            $this->consultsTheFlagInCode('{{-- gated on $isProforma, honest --}}<td>{{ __(\'Tax\') }}</td>'),
        );
        $this->assertFalse(
            $this->consultsTheFlagInCode("@php\n    // \$isProforma is handled upstream\n    \$x = 1;\n@endphp"),
        );
        // Directives are matched at end-of-line, which is how every one of them is
        // written in this tree. A directive buried mid-line reads as UNGATED — the
        // check fails CLOSED, which is the safe direction for a census.
        $this->assertTrue(
            $this->consultsTheFlagInCode("@if(\$isProforma ?? false)\n<td>x</td>\n@endif"),
        );
        $this->assertFalse(
            $this->consultsTheFlagInCode('@if($isProforma ?? false)<td>x</td>@endif'),
            'a mid-line directive is not recognised — the census fails closed, never open',
        );
        $this->assertTrue(
            $this->consultsTheFlagInCode("@php\n    \$showTaxColumn = ! (\$isProforma ?? false);\n@endphp"),
        );
    }

    private function invoice(): Document
    {
        return $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
    }

    private function salesOrder(): Document
    {
        $order = $this->dpConfirmedInvoice([$this->dpPhysicalLine()], [
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
        ]);
        $order->refresh();

        return $order;
    }
}
