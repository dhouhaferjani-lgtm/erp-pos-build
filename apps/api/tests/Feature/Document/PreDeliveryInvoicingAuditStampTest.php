<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveryComplianceGate;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T25e**: the audit stamp.
 *
 * NOT an acknowledgement and NOT an opt-out — 3E builds neither, because both
 * are only reachable under `allow`, which the resolver refuses. This records
 * what was TRUE at post time so a later audit can separate three populations
 * that look identical in the ledger:
 *
 *   - posted WITH goods issued (compliant),
 *   - posted BEFORE this policy existed (no stamp at all),
 *   - posted under a policy that permitted it (would carry `policy = allow`).
 *
 * The load-bearing safety claim — `payload` is not a fiscal-hash input — is
 * asserted here rather than assumed.
 */
class PreDeliveryInvoicingAuditStampTest extends TestCase
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

    public function test_a_delivered_invoice_is_stamped_with_the_policy_and_the_delivery_state(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);

        $stamp = $this->postingService()->post($invoice)->payload[DeliveryComplianceGate::STAMP_KEY];

        $this->assertSame('require_delivery_first', $stamp['policy']);
        $this->assertSame('country', $stamp['policy_source']);
        $this->assertTrue($stamp['has_ever_issued_goods']);
        $this->assertTrue($stamp['has_goods_issued']);
        $this->assertSame([$deliveryNote->id], $stamp['delivery_note_ids']);
        $this->assertNotEmpty($stamp['stamped_at']);
    }

    /**
     * The population D-29 exists for. Both predicates are recorded, so an auditor
     * can tell "delivered then returned" from "never delivered" — which the
     * ledger alone cannot show.
     */
    public function test_a_delivered_then_returned_invoice_records_both_predicates(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine('3.0000')]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('3.0000')]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);
        $this->returnEverything($invoice, '3.0000');

        $stamp = $this->postingService()->post($invoice)->payload[DeliveryComplianceGate::STAMP_KEY];

        $this->assertTrue($stamp['has_ever_issued_goods'], 'It WAS delivered.');
        $this->assertFalse($stamp['has_goods_issued'], 'And it all came back.');
    }

    public function test_a_service_only_invoice_is_stamped_too(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        $stamp = $this->postingService()->post($invoice)->payload[DeliveryComplianceGate::STAMP_KEY];

        $this->assertSame('require_delivery_first', $stamp['policy']);
        $this->assertFalse($stamp['has_ever_issued_goods']);
        $this->assertSame([], $stamp['delivery_note_ids']);
    }

    public function test_a_company_override_is_recorded_as_the_source(): void
    {
        $this->dpCompany->update(['pre_delivery_invoicing_policy' => 'require_delivery_first']);
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);

        $stamp = $this->postingService()->post($invoice)->payload[DeliveryComplianceGate::STAMP_KEY];

        $this->assertSame('company', $stamp['policy_source']);
    }

    /**
     * 🚨 The claim the whole design rests on: writing the stamp does NOT move the
     * sealed bytes. Two identical invoices, same chain position, byte-identical
     * hash inputs — the second one is posted through the same path and its hash
     * is computed over document_number / posted_at / total / currency only.
     */
    public function test_the_fiscal_hash_is_computed_without_the_payload(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);
        $posted = $this->postingService()->post($invoice);

        $this->assertNotNull($posted->fiscal_hash);

        $this->assertArrayHasKey(
            DeliveryComplianceGate::STAMP_KEY,
            $posted->payload,
            'The stamp is on the payload of a SEALED document.',
        );

        $hashService = app(FiscalHashService::class);

        $fields = [
            'document_number' => (string) $posted->document_number,
            'posted_at' => now()->toDateString(),
            'total' => (string) $posted->total,
            'currency' => (string) $posted->currency,
        ];

        // The serializer reads FOUR keys and nothing else: handing it a payload —
        // stamp and all — cannot move a single byte of the hashed input. That is
        // the whole safety argument for writing the stamp on a fiscal document,
        // and it is asserted rather than assumed.
        $this->assertSame(
            $hashService->serializeForHashing($fields),
            $hashService->serializeForHashing(
                $fields + [DeliveryComplianceGate::STAMP_KEY => ['policy' => 'require_delivery_first']]
            ),
            'payload must not be a fiscal-hash input — if it ever becomes one, the stamp has to move.',
        );

        // ...and the sealed document is chain-valid WITH the stamp on it.
        $this->assertTrue(
            $hashService->verifyChain(
                [[
                    'input' => $hashService->serializeForHashing($fields),
                    'hash' => (string) $posted->fiscal_hash,
                    'previous_hash' => $posted->previous_hash,
                ]],
                $this->dpCompany->refresh()->fiscal_chain_seed,
            ),
        );
    }

    public function test_the_stamp_survives_a_subsequent_payload_append(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpServiceLine()]);
        $posted = $this->postingService()->post($invoice);

        $posted->update([
            'payload' => array_merge($posted->payload ?? [], ['some_later_decision' => ['ok' => true]]),
        ]);

        $payload = $posted->refresh()->payload;

        $this->assertArrayHasKey(DeliveryComplianceGate::STAMP_KEY, $payload);
        $this->assertArrayHasKey('some_later_decision', $payload);
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
