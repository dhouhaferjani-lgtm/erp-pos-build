<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25b + T25d**: the compliance boundary.
 *
 * Under `require_delivery_first` a definitive goods invoice may not be POSTED
 * before its goods have left. Enforcement lives in
 * `DocumentPostingService::post()` — the chokepoint that precedes
 * `postWithFiscalChain()` — and NOT in the controller, because a controller-only
 * gate is bypassed by every other `post()` caller.
 *
 * The predicate is `hasEverIssuedGoods()`, NOT `hasGoodsIssued()` (D-29). The
 * difference is a whole population: an invoice delivered in full and then
 * returned in full. It was delivered. It posts.
 *
 * This class is also the **inversion** half of
 * {@see DeliveryGateUnificationCharacterisationTest}: exactly one row of that
 * table changes — "standalone with physical lines: posts → refused". Every other
 * row is re-asserted here.
 */
class PreDeliveryInvoicingGateTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    private function postingService(): DocumentPostingService
    {
        return app(DocumentPostingService::class);
    }

    // ── THE FLIP ─────────────────────────────────────────────────────────────

    public function test_a_standalone_physical_invoice_is_refused_with_the_typed_code(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        try {
            $this->postingService()->post($invoice);
            $this->fail('A standalone goods invoice must not post under require_delivery_first.');
        } catch (DeliveryRequiredBeforeInvoiceException $e) {
            $this->assertSame('require_delivery_first', $e->policy);
            $this->assertSame('country', $e->policySource);
            $this->assertSame([], $e->draftDeliveryNotes);
            $this->assertTrue($e->canAutoConfirm, 'Every physical line has a resolvable product and location.');
        }

        $this->assertSame(DocumentStatus::Confirmed, $invoice->refresh()->status);
        $this->assertNull($invoice->fiscal_hash, 'The refusal must precede the seal.');
    }

    /**
     * T25d — refuse-and-REDIRECT. The refusal has to name the compliant
     * alternatives or it is a dead end that gets worked around by back-dating.
     */
    public function test_the_refusal_names_the_compliant_alternatives(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        try {
            $this->postingService()->post($invoice);
            $this->fail('expected refusal');
        } catch (DeliveryRequiredBeforeInvoiceException $e) {
            $actions = array_column($e->alternatives(), 'action');

            $this->assertContains('create_and_confirm_delivery_note', $actions);
            $this->assertContains('order_with_advance_payment', $actions);

            foreach ($e->alternatives() as $alternative) {
                $this->assertNotSame('', $alternative['description'], 'No untranslated/empty server copy.');
            }
        }
    }

    /**
     * The controller surface: a 422 carrying the machine code and the policy, so
     * the guided flow can be rendered without guessing.
     */
    public function test_the_http_surface_returns_the_machine_code_and_the_policy(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $response = $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DELIVERY_REQUIRED_BEFORE_INVOICE');
        $response->assertJsonPath('error.details.policy', 'require_delivery_first');
        $response->assertJsonPath('error.details.policy_source', 'country');
        $response->assertJsonPath('error.details.can_auto_confirm', true);
        $this->assertIsArray($response->json('error.details.alternatives'));
    }

    // ── THE PREDICATE FIX (D-29 / fiscal N-5) ────────────────────────────────

    public function test_an_invoice_delivered_then_fully_returned_still_posts(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('3.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);
        $this->returnEverything($invoice, '3.0000');

        $posted = $this->postingService()->post($invoice);

        $this->assertSame(
            DocumentStatus::Posted,
            $posted->status,
            'Delivery HAPPENED. hasGoodsIssued() is false here — using it as the gate predicate '
            .'would block a compliant document forever.',
        );
    }

    // ── THE ROWS THAT MUST NOT CHANGE ────────────────────────────────────────

    public function test_a_converted_invoice_from_a_confirmed_dn_still_posts(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $this->assertSame(DocumentStatus::Posted, $this->postingService()->post($invoice)->status);
    }

    public function test_an_order_sourced_invoice_with_complete_delivery_still_posts(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkOrderShape($invoice, [$deliveryNote]);

        $this->assertSame(DocumentStatus::Posted, $this->postingService()->post($invoice)->status);
    }

    public function test_a_service_only_invoice_is_unaffected(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        $this->assertSame(DocumentStatus::Posted, $this->postingService()->post($invoice)->status);
    }

    public function test_a_credit_note_with_physical_lines_is_unaffected(): void
    {
        $creditNote = $this->dpConfirmedCreditNote([$this->dpPhysicalLine()]);

        $this->assertSame(DocumentStatus::Posted, $this->postingService()->post($creditNote)->status);
    }

    /**
     * 🚨 The POS is NOT wired into this gate, and must never be by analogy.
     *
     * A POS `SALE_RECEIPT` carrying `invoice_type_code = REFUND/VOID` is a
     * `pos_receipts` row projected by the fiscal event engine — it is not a
     * `documents` invoice, it never reaches `DocumentPostingService::post()`, and
     * the delivery question is meaningless for it (the customer walks out with
     * the goods). Asserted structurally so nobody wires the gate into the POS
     * chokepoint because "an invoice is an invoice".
     */
    public function test_the_pos_module_does_not_reference_the_delivery_policy_gate(): void
    {
        $posPath = app_path('Modules/POS');
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($posPath)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'DeliveryComplianceGate')
                || str_contains($contents, 'PreDeliveryInvoicingPolicyResolver')
                || str_contains($contents, 'DeliveryRequiredBeforeInvoiceException')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'The pre-delivery invoicing gate must not reach into the POS lane.');
    }

    // ── T25d's hard boundary, asserted by count ──────────────────────────────

    /**
     * Sub-wave 3E creates NO advance payment and posts NO advance GL. The refusal
     * REDIRECTS to the shipped advance path; it never drives it. The
     * advance-reversal lane is frozen and carries a live red defect, so a Wave-3
     * write into that surface would be a boundary violation, not a feature.
     */
    public function test_no_payment_and_no_journal_entry_is_created_by_a_refusal(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);

        $paymentsBefore = DB::table('payments')->count();
        $entriesBefore = JournalEntry::query()->count();

        try {
            $this->postingService()->post($invoice);
        } catch (DeliveryRequiredBeforeInvoiceException) {
            // expected
        }

        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertSame($entriesBefore, JournalEntry::query()->count());
    }

    private function returnEverything(Document $invoice, string $quantity): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'partner_id' => $this->dpPartner->id,
            'location_id' => $this->dpLocation->id,
            'source_document_id' => $invoice->id,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Confirmed,
            'fiscal_status' => FiscalStatus::Draft,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'document_number' => 'DP-RN-'.bin2hex(random_bytes(4)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'line_number' => 1,
            'product_id' => $this->dpProduct->id,
            'location_id' => $this->dpLocation->id,
            'description' => '3E return line',
            'quantity' => $quantity,
            'unit_price' => '100.000',
            'tax_rate' => '0.00',
            'line_total' => '0.000',
        ]);
    }
}
