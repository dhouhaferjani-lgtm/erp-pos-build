<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Document\Application\DTOs\CreatePOSAccountChargeDraftCommand;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Application\Services\POSAccountChargeDraftService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 / F-107 — until provenance is a persisted COLUMN, an Invoice that came
 * from the opening-balance importer or from a POS account charge is REFUSED on
 * every payment entry point.
 *
 * WHY THIS IS THE WHOLE POINT OF THE SEQUENCING RULE. `ArApOpeningService`
 * mints an AP (payable) opening as `DocumentType::Invoice` — byte for byte the
 * same type an AR (receivable) opening gets. Admitting "Invoice + posted" as a
 * receivable clearing, as N-6 did, therefore books Dr bank / Cr 411 for money
 * the company OWES: cash recorded as arriving, a customer receivable driven
 * negative, and a supplier's payable left standing. There is no column to tell
 * the two apart yet (that is lane C-PROV0), so this lane refuses BOTH sides
 * rather than settling the safe one and guessing the other. Spec rules 2–5
 * admit them again, per side, in C-0a1.
 *
 * GATE r1 / F-2 NARROWED THIS. The AR side of a historical opening is no longer
 * refused: `opening_balance_import_rows.row_type` + `mapped_entity_id` prove the
 * side today, so refusing a provable AR opening bought no safety and cost a
 * cutover its whole open-receivables ledger. What stays refused, and is what this
 * suite now pins, is the AP side and the side that cannot be proven.
 * `HistoricalOpeningSideSettlementTest` carries the admitted AR case.
 *
 * The POS case is the mirror-image hazard: a POS account-charge invoice already
 * carries a 411 from its sealed fiscal event, so a payment on it is ALWAYS a
 * clearing and never a 419 advance — but N-6's posted-ness test would book a
 * `confirmed` one as an advance and leave the adopted receivable outstanding.
 *
 * These documents are minted through the REAL services, not hand-built, because
 * the detector reads markers those services write; a hand-built fixture would
 * test the test.
 */
final class HistoricalAndPosInvoicesRefusedBeforeProvenanceTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('hist-pos-refused');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provenanceFamilies(): iterable
    {
        yield 'historical AP opening (the dangerous one: Cr 411 for a PAYABLE)' => ['ap_opening'];
        yield 'historical opening with no provable side' => ['unknown_side_opening'];
        yield 'POS account charge, confirmed' => ['pos_confirmed'];
        yield 'POS account charge, posted' => ['pos_posted'];
    }

    #[DataProvider('provenanceFamilies')]
    public function test_the_direct_payment_endpoint_refuses_it(string $family): void
    {
        [$document, $partner, $reason] = $this->mint($family);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $document->id, 'amount' => '100.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', $reason);
        $this->assertNoAllocationWasWritten($document->id);
    }

    #[DataProvider('provenanceFamilies')]
    public function test_the_manual_allocation_preview_refuses_it(string $family): void
    {
        [$document, $partner, $reason] = $this->mint($family);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/preview-allocation', [
            'partner_id' => $partner->id,
            'payment_amount' => '100.000',
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $document->id, 'amount' => '100.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', $reason);
        $this->assertNoAllocationWasWritten($document->id);
    }

    #[DataProvider('provenanceFamilies')]
    public function test_the_manual_allocation_execute_refuses_it(string $family): void
    {
        [$document, $partner, $reason] = $this->mint($family);
        $payment = $this->makeUnallocatedPayment('100.000', $partner);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $document->id, 'amount' => '100.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', $reason);
        $this->assertNoAllocationWasWritten($document->id);
    }

    #[DataProvider('provenanceFamilies')]
    public function test_the_apply_deposit_path_refuses_it(string $family): void
    {
        [$document, $partner, $reason] = $this->mint($family);
        $deposit = $this->makeUnallocatedDeposit('100.000', $partner);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payments/{$deposit->id}/apply-deposit", [
            'document_id' => $document->id,
            'amount' => '100.000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', $reason);
        $this->assertNoAllocationWasWritten($document->id);
    }

    /**
     * Gate r1 / F-7 — the AUTO path SKIPS these documents; it does not refuse the
     * request. r1's version of this test discarded the response and asserted only
     * that nothing was written, which is exactly what hid F-1: "nothing written"
     * was true both when the sweep passed over the row and when it threw a 422
     * and rolled the whole collection back. The status code is the difference,
     * so it is asserted.
     *
     * `AutoAllocationSkipsRefusedDocumentsTest` carries the other half — that the
     * allocatable documents queued BEHIND the refused one are still collected.
     */
    #[DataProvider('provenanceFamilies')]
    public function test_the_auto_allocation_sweep_skips_it_without_refusing_the_request(string $family): void
    {
        [$document, $partner] = $this->mint($family);
        $payment = $this->makeUnallocatedPayment('100.000', $partner);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertNoAllocationWasWritten($document->id);
    }

    /**
     * @return array{Document, Partner, string}
     */
    private function mint(string $family): array
    {
        return match ($family) {
            'ap_opening' => [
                $this->historicalOpening(OpeningBatchType::ApOpenItems, $this->vendor),
                $this->vendor,
                'historical_opening_provenance',
            ],
            'unknown_side_opening' => [
                $this->openingWithoutProvableSide(),
                $this->customer,
                'historical_opening_provenance',
            ],
            'pos_confirmed' => [
                $this->posAccountChargeInvoice(DocumentStatus::Confirmed),
                $this->customer,
                'pos_derived_provenance',
            ],
            'pos_posted' => [
                $this->posAccountChargeInvoice(DocumentStatus::Posted),
                $this->customer,
                'pos_derived_provenance',
            ],
            default => throw new \LogicException("unknown provenance family {$family}"),
        };
    }

    private function historicalOpening(OpeningBatchType $type, Partner $partner): Document
    {
        $batchService = app(OpeningBalanceBatchService::class);
        $batch = $batchService->createBatch(
            $this->company,
            $type,
            now(),
            'C0A0-'.$type->value.'-'.Str::upper(Str::random(4)),
            $this->user->id,
            'phpunit',
        );
        $batchService->addImportRows($batch, [[
            'partner_code' => (string) $partner->code,
            'external_invoice_number' => 'LEG-'.Str::upper(Str::random(5)),
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total' => '100.000',
            'open_amount' => '100.000',
            'currency' => 'TND',
            'document_type' => 'invoice',
            'notes' => null,
        ]]);

        $service = app(ArApOpeningService::class);
        $service->validateBatch($batch->refresh());
        $service->postBatch($batch->refresh(), $this->user->id);

        /** @var Document $document */
        $document = Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $partner->id)
            ->where('is_historical', true)
            ->latest('created_at')
            ->firstOrFail();

        // The marker the detector reads must actually be there — if the minting
        // service stops writing it, this suite must fail LOUDLY rather than
        // quietly stop testing anything.
        self::assertTrue($document->is_historical);
        self::assertStringStartsWith('Opening Balance Batch: ', (string) $document->reference);

        return $document;
    }

    /**
     * A real AR opening whose import-row link has been severed, so the AR/AP
     * evidence the resolver reads is genuinely absent. Every marker that
     * identifies it as an opening survives; only the side does not.
     */
    private function openingWithoutProvableSide(): Document
    {
        $document = $this->historicalOpening(OpeningBatchType::ArOpenItems, $this->customer);

        OpeningBalanceImportRow::query()
            ->where('mapped_entity_id', $document->id)
            ->update(['mapped_entity_id' => null]);

        return $document;
    }

    private function posAccountChargeInvoice(DocumentStatus $status): Document
    {
        $document = app(POSAccountChargeDraftService::class)->createDraft(
            new CreatePOSAccountChargeDraftCommand(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                partnerId: $this->customer->id,
                fiscalEventId: (string) Str::uuid(),
                accountChargeUuid: (string) Str::uuid(),
                businessDate: now()->toDateString(),
                currencyCode: 'TND',
                currencyScale: 3,
                subtotal: '100.000',
                vatTotal: '0.000',
                total: '100.000',
                transactionDiscountAmount: '0.000',
                lineItems: [[
                    'sku' => 'SKU-1',
                    'name' => 'Consultation',
                    'quantity' => '1.0000',
                    'unit_price' => '100.000',
                    'line_discount_amount' => '0.000',
                    'line_subtotal' => '100.000',
                    'line_vat' => '0.000',
                    'vat_rate' => '0.00',
                ]],
                payloadSnapshot: ['source' => 'c0a0-test'],
                dueDate: now()->addDays(30)->toDateString(),
            ),
        );

        // The draft is authored `draft`; this suite is about PROVENANCE, so the
        // document is advanced past rule 1 (which would otherwise refuse it as
        // not-live and mask the provenance verdict entirely).
        Document::query()->whereKey($document->id)->update([
            'status' => $status,
            'balance_due' => '100.000',
        ]);

        $document = $document->refresh();
        self::assertStringStartsWith('POS-ACCOUNT-CHARGE:', (string) $document->reference);

        return $document;
    }

    private function assertNoAllocationWasWritten(string $documentId): void
    {
        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $documentId)->count(),
            'no allocation row may exist for a refused document',
        );
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('source_id', $documentId)
                ->where('source_type', 'payment_allocation')
                ->count(),
            'no allocation journal entry may exist for a refused document',
        );
    }
}
