<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Application\Services\ProformaOutputPolicy;
use App\Modules\Document\Application\Services\ProformaPresenter;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * C-F0w / SPEC §2.4 — the DOCUMENT RESOURCE carries the proforma predicate.
 *
 * WHY THIS EXISTS. C-F0 removed the VAT from the PDF of a confirmed-unposted
 * fiscal document. The web detail pages kept rendering it, because the payload
 * they read never told them what kind of document they had:
 * `CreditNoteDetail.tsx:74-78` said so in a comment — "if a credit note is ever
 * POSTED without a seal, this view cannot tell" — and guessed from `status`.
 *
 * A status heuristic is not the predicate. `ProformaOutputPolicy` keys on the
 * SEAL: a `Paid`-but-never-sealed invoice is a proforma and a status heuristic
 * calls it settled; a cancelled-after-sealing invoice is NOT a proforma and a
 * status heuristic that only knows `posted` would hide the VAT on a document the
 * ledger and the customer's copy both carry. So the resource emits the policy's
 * own answer, and this test pins that they can never disagree — every case
 * asserts `is_proforma` against a live `ProformaOutputPolicy` call rather than
 * against a hand-written expectation, and the shape provider carries the five
 * shapes the reviewer named plus the two the policy exists to refuse.
 */
final class ProformaResourceTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures('TN');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shapeProvider(): array
    {
        return [
            'draft invoice' => ['draftInvoice', true],
            'confirmed invoice' => ['confirmedInvoice', true],
            'paid, never sealed' => ['paidNeverSealedInvoice', true],
            'posted, never sealed' => ['postedNeverSealedInvoice', true],
            'confirmed credit note' => ['confirmedCreditNote', true],
            'posted and sealed' => ['postedInvoice', false],
            'sealed then cancelled' => ['cancelledSealedInvoice', false],
            'opening balance (historical)' => ['historicalInvoice', false],
            'non-fiscal type (quote)' => ['confirmedQuote', false],
        ];
    }

    #[DataProvider('shapeProvider')]
    public function test_is_proforma_equals_the_policy(string $factory, bool $expected): void
    {
        $document = $this->{$factory}();

        /** @var ProformaOutputPolicy $policy */
        $policy = $this->app->make(ProformaOutputPolicy::class);

        $data = DocumentData::fromModel($document, true, 3, $this->present($document));

        $this->assertSame($expected, $policy->isProforma($document), 'fixture drifted');
        $this->assertSame(
            $policy->isProforma($document),
            $data->is_proforma,
            'the resource must emit the policy answer, never a status heuristic',
        );
    }

    #[DataProvider('shapeProvider')]
    public function test_proforma_totals_are_present_only_on_a_proforma(string $factory, bool $expected): void
    {
        $document = $this->{$factory}();

        $data = DocumentData::fromModel($document, true, 3, $this->present($document));

        if ($expected) {
            $this->assertNotNull($data->proforma, 'a proforma must carry its VAT-free totals');
        } else {
            $this->assertNull($data->proforma, 'a definitive document must not carry proforma totals');
        }
    }

    public function test_the_proforma_totals_are_tax_inclusive_and_close_over_the_estimated_total(): void
    {
        $document = $this->confirmedInvoice();

        $data = DocumentData::fromModel($document, true, 3, $this->present($document));

        $this->assertNotNull($data->proforma);

        // 2 × 100.000 net at 19% ⇒ 238.000 gross, and the document total is the
        // same figure: nothing on the page can be subtracted to recover the VAT.
        $this->assertSame('238.000', $data->proforma->estimated_total);
        $this->assertSame('238.000', $data->proforma->gross_lines);
        $this->assertNull($data->proforma->stamp_duty);
        $this->assertNull($data->proforma->discount);
        $this->assertNull($data->proforma->adjustment);

        $this->assertCount(1, $data->proforma->lines);
        $line = $data->proforma->lines[0];
        $this->assertSame($document->lines->firstOrFail()->id, $line->line_id);
        $this->assertSame('119.000', $line->unit_price);
        $this->assertSame('238.000', $line->line_total);
    }

    public function test_a_definitive_invoice_keeps_its_net_and_tax_figures(): void
    {
        $document = $this->postedInvoice();

        $data = DocumentData::fromModel($document, true, 3, $this->present($document));

        $this->assertFalse($data->is_proforma);
        $this->assertSame('200.000', $data->subtotal);
        $this->assertSame('38.000', $data->tax_amount);
    }

    private function present(Document $document): mixed
    {
        /** @var ProformaPresenter $presenter */
        $presenter = $this->app->make(ProformaPresenter::class);

        return $presenter->present($document);
    }

    private function draftInvoice(): Document
    {
        return $this->priced($this->dpConfirmedInvoice(
            [$this->dpPhysicalLine()],
            ['status' => DocumentStatus::Draft],
        ));
    }

    private function confirmedInvoice(): Document
    {
        return $this->priced($this->dpConfirmedInvoice([$this->dpPhysicalLine()]));
    }

    private function confirmedCreditNote(): Document
    {
        return $this->priced($this->dpConfirmedCreditNote([$this->dpPhysicalLine()]));
    }

    private function paidNeverSealedInvoice(): Document
    {
        return $this->force($this->confirmedInvoice(), ['status' => DocumentStatus::Paid]);
    }

    private function postedNeverSealedInvoice(): Document
    {
        return $this->force($this->confirmedInvoice(), ['status' => DocumentStatus::Posted]);
    }

    private function postedInvoice(): Document
    {
        return $this->force($this->confirmedInvoice(), [
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => str_repeat('a', 64),
            'chain_sequence' => 1,
        ]);
    }

    private function cancelledSealedInvoice(): Document
    {
        return $this->force($this->confirmedInvoice(), [
            'status' => DocumentStatus::Cancelled,
            'fiscal_status' => FiscalStatus::Voided,
            'fiscal_hash' => str_repeat('b', 64),
            'chain_sequence' => 2,
        ]);
    }

    private function historicalInvoice(): Document
    {
        return $this->force($this->confirmedInvoice(), [
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'is_historical' => true,
        ]);
    }

    private function confirmedQuote(): Document
    {
        return $this->priced($this->dpCreateDocument([
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-PROFORMA-0001',
        ], [$this->dpPhysicalLine()]));
    }

    /**
     * A non-zero tax amount on the single line, so hiding the VAT is observable.
     */
    private function priced(Document $document): Document
    {
        $document->lines->firstOrFail()->forceFill([
            'tax_rate' => '19.00',
            'tax_amount' => '38.000',
            'line_total' => '200.000',
        ])->save();

        return $this->force($document, [
            'subtotal' => '200.000',
            'tax_amount' => '38.000',
            'total' => '238.000',
            'balance_due' => '238.000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function force(Document $document, array $attributes): Document
    {
        $document->forceFill($attributes)->save();
        $document->refresh();
        $document->load('lines');

        return $document;
    }
}
