<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

        // Gate r3 / R3-1 — say WHY, or this assertion is vacuous. Since W4-3 an AP
        // opening is a `SupplierInvoice`, and `getOpenInvoices()` selects only
        // `Invoice|SalesOrder`, so it is excluded by the SQL TYPE mirror rather than
        // by any refusal running. That is the intended guarantee, but it is a
        // different guarantee from the one this test used to make, and a reader has
        // to be able to tell which one is being pinned.
        self::assertSame(
            DocumentType::SupplierInvoice,
            $opening->type,
            'precondition: the AP opening is supplier-typed, so the SQL mirror — not a refusal — keeps it out',
        );
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
        // Gate r3 / R3-1 — same caveat as the preview test: this row is out of the
        // sweep by TYPE, not by a refusal, so on its own it no longer exercises the
        // direction guard. `test_the_sweep_skips_a_partner_role_mismatch_...` below
        // is what pins the guard's placement on this entry point.
        self::assertSame(DocumentType::SupplierInvoice, $opening->type);
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
        $this->assertAllocatedTo($native->id, '50.000');
    }

    /**
     * The other half of the rule: a document the OPERATOR named still throws.
     * Skipping a manually chosen target would silently do nothing and tell them
     * the payment was allocated.
     *
     * OWNER RULING OQ-74 (recorded `d0cfa624f`) — ALLOW. The REASON changed here,
     * the refusal did not. W4-3 mints an AP opening as a `SupplierInvoice`, so it
     * is no longer refused for missing provenance: it is refused because this is
     * the AR allocation path, which posts `Dr bank / Cr 411`, and a payable
     * settles `Dr 401 / Cr bank` through `PaymentController::store()`. Same
     * outcome, truer reason — and the positive case (the supplier arm accepting
     * it) is pinned in `HistoricalOpeningSideSettlementTest`.
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
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }

    /**
     * GATE r3 / R3-1 — the pin for the r2 F-1 fix, which had ZERO coverage.
     *
     * r2's CRITICAL was that a THROW on `PaymentAllocationService`'s direction check
     * dead-letters a SEALED device fiscal fact after five Horizon retries, because
     * both queued bridges enter that method with FIFO. The fix was to skip on the
     * server-chosen path and throw only on MANUAL. Reverting it went unnoticed by
     * the entire Treasury directory — the r2 tripwires had stopped working for two
     * independent reasons, both introduced by this lane: the scaffold's vendor
     * became `Both`, and an AP opening became a `SupplierInvoice` that
     * `getOpenInvoices()` never offers.
     *
     * This is the one shape that both REACHES the sweep and TRIPS the predicate:
     * a supplier-ONLY partner holding an ordinary posted customer `Invoice` (the
     * mismatch — `getOpenInvoices()` selects `Invoice|SalesOrder`, so it is offered)
     * and, behind it, a confirmed `SalesOrder` (which the predicate passes, because
     * an order carries no AR/AP side of its own). The mismatch must be SKIPPED with
     * its reason, the order must still be collected, and nothing may throw.
     *
     * Revert the MANUAL gate and this goes red immediately: the FIFO call throws
     * `HttpResponseException` out of the projection entry point.
     */
    public function test_the_sweep_skips_a_partner_role_mismatch_without_throwing_and_still_collects(): void
    {
        $supplierOnly = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUPONLY-'.Str::upper(Str::random(5)),
            'name' => 'Fournisseur Uniquement',
            'type' => PartnerType::Supplier,
        ]);

        // The mismatch, and OLDEST so FIFO reaches it first.
        $misTyped = $this->makeDocument(DocumentType::Invoice, DocumentStatus::Posted, '100.000', $supplierOnly, 'INV');
        Document::query()->whereKey($misTyped->id)->update(['document_date' => '2026-01-01']);

        // Behind it, a document the predicate lets through: an order has no AR/AP
        // side, so `directionMatchesPartner()` returns true for it whatever the
        // partner's role is.
        $order = $this->makeDocument(DocumentType::SalesOrder, DocumentStatus::Confirmed, '80.000', $supplierOnly, 'SO');
        Document::query()->whereKey($order->id)->update(['document_date' => '2026-06-01']);

        // 150.000, not 50.000, and the reason is a finding in its own right: the
        // PREVIEW still offers the mis-typed row (the direction predicate is not part
        // of `getOpenInvoices()`'s SQL mirror), so a payment small enough to be
        // exhausted by the mismatch would leave the order out of the preview's
        // allocation list entirely and this test would fail for a reason that has
        // nothing to do with the guard. Sized to cover both, the execute-side skip
        // is isolated. The preview/execute disagreement is recorded as a residual in
        // the handback — it wastes an offer, it does not move money.
        $payment = $this->makeUnallocatedPayment('150.000', $supplierOnly);

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

        $this->assertTrue($result['success'], 'the sweep must not throw — a throw here dead-letters a sealed fiscal fact');
        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $misTyped->id)->count(),
            'the mis-typed document must be SKIPPED, not allocated — that is the Dr bank / Cr 411 defect',
        );
        $this->assertAllocatedTo($order->id, '50.000'); // the preview gave the mismatch 100 of the 150 and the order the remaining 50; the execute then skipped the mismatch
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
