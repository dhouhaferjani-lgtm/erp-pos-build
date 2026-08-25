<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 fix round r1 / gate F-1 (CRITICAL) — the AUTO sweep SKIPS a refused
 * document; it never aborts the collection.
 *
 * The r1 gate proved the shape empirically (its leg 9). `getOpenInvoices()`
 * selects `Invoice + posted + open` with no provenance predicate, and a
 * historical opening is EXACTLY that by construction — dated at the cutover, so
 * FIFO reaches it FIRST on every affected partner. The execute loop then called
 * the THROWING classifier variant on the locked row, so one unpayable opening
 * took down the whole request: the native posted invoices queued behind it were
 * never reached, and the transaction rolled back.
 *
 * That is a collection outage, and it is worse than an HTTP 422, because the
 * same `applyAllocationFromCommand(… FIFO …)` is what two QUEUED FISCAL
 * PROJECTIONS call — `TreasuryAccountPaymentBridge:216` (device-authored POS
 * `ACCOUNT_PAYMENT`) and `TreasuryDepositBridge:200` (`DEPOSIT_RECEIPT`).
 * `DocumentNotAllocatableException` is a plain `DomainException`, so it is NOT
 * the `NonRetryableProjectionException` the job special-cases: it lands on the
 * generic `catch (Throwable)` arm, is re-thrown for Horizon retry, burns all 5
 * tries and dead-letters a SEALED device fiscal fact.
 *
 * The rule this suite pins: a document the OPERATOR named (manual / direct)
 * still throws — they chose it and deserve to be told why. A document the
 * SERVER chose (FIFO / due-date) is skipped, because the operator asked to
 * collect a payment, not to allocate to that particular row.
 */
final class AutoAllocationSkipsRefusedDocumentsTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('auto-skip');
    }

    /**
     * The gate's leg 9, as a permanent test: an AP-side opening (refused, and
     * OLDEST so FIFO hits it first) must not stop the native invoice behind it
     * from being collected.
     */
    public function test_the_http_auto_path_skips_a_refused_opening_and_still_collects_the_native_invoice(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $native = $this->nativePostedInvoice($this->vendor, '80.000');

        $payment = $this->makeUnallocatedPayment('50.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();

        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $opening->id)->count(),
            'the refused AP opening must not be allocated against',
        );
        $this->assertAllocatedTo($native->id, '50.000');
    }

    public function test_the_http_auto_path_skips_a_pos_derived_invoice_and_still_collects_the_native_invoice(): void
    {
        $pos = $this->makePosAccountChargeInvoice(DocumentStatus::Posted, '100.000');
        Document::query()->whereKey($pos->id)->update(['document_date' => '2026-01-01']);
        $native = $this->nativePostedInvoice($this->customer, '80.000');

        $payment = $this->makeUnallocatedPayment('50.000', $this->customer);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $pos->id)->count());
        $this->assertAllocatedTo($native->id, '50.000');
    }

    /**
     * The PREVIEW must offer exactly what the execute will allocate. Two halves
     * of one surface disagreeing about what is payable is the defect N-6 named
     * and this lane re-introduced from the other side.
     */
    public function test_the_auto_preview_offers_exactly_what_the_execute_allocates(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $native = $this->nativePostedInvoice($this->vendor, '80.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/preview-allocation', [
            'partner_id' => $this->vendor->id,
            'payment_amount' => '50.000',
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();

        /** @var list<array{document_id: string}> $offered */
        $offered = $response->json('data.allocations');
        $offeredIds = array_column($offered, 'document_id');

        $this->assertNotContains($opening->id, $offeredIds, 'a refused opening must never be OFFERED');
        $this->assertContains($native->id, $offeredIds);
    }

    /**
     * The queued-projection entry point, called directly — this is the exact
     * signature `TreasuryAccountPaymentBridge` and `TreasuryDepositBridge` use.
     * A throw here dead-letters a sealed device fiscal fact after 5 retries.
     */
    public function test_the_queued_projection_entry_point_does_not_throw_on_a_refused_opening(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $native = $this->nativePostedInvoice($this->vendor, '80.000');
        $payment = $this->makeUnallocatedPayment('50.000', $this->vendor);

        $result = app(PaymentAllocationService::class)->applyAllocationFromCommand(
            new ApplyPaymentAllocationCommand(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                paymentId: $payment->id,
                allocationMethod: AllocationMethod::FIFO,
                actorUserId: $this->user->id,
                source: 'pos_account_payment',
            ),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
        $this->assertAllocatedTo($native->id, '50.000');
    }

    /**
     * The other half of the rule: a document the OPERATOR named still throws.
     * Skipping a manually chosen target would silently do nothing and tell them
     * the payment was allocated.
     */
    public function test_the_manual_path_still_throws_for_the_same_document(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $payment = $this->makeUnallocatedPayment('50.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $opening->id, 'amount' => '50.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'historical_opening_provenance');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }

    /**
     * A sweep whose ENTIRE offered set is refused must still return cleanly with
     * nothing allocated — not a 422, and not a partial write.
     */
    public function test_an_auto_sweep_whose_whole_set_is_refused_returns_cleanly(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $payment = $this->makeUnallocatedPayment('50.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }

    private function nativePostedInvoice(Partner $partner, string $total): Document
    {
        $invoice = $this->makeDocument(DocumentType::Invoice, DocumentStatus::Posted, $total, $partner, 'INV');
        Document::query()->whereKey($invoice->id)->update(['document_date' => now()->toDateString()]);

        return $invoice->refresh();
    }

    /**
     * Compare money with `bccomp` at the storage scale — never as a float and
     * never as string equality (rule 19).
     *
     * @param  numeric-string  $expected
     */
    private function assertAllocatedTo(string $documentId, string $expected): void
    {
        $sum = (string) (PaymentAllocation::query()->where('document_id', $documentId)->sum('amount') ?: '0');

        self::assertSame(0, bccomp($sum, $expected, 3), "allocated to {$documentId} must be {$expected}, got {$sum}");
    }
}
