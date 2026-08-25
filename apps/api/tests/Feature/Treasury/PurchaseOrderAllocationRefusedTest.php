<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 — a purchase order may not receive a payment on ANY entry point.
 *
 * SPEC §2.1 rule 9 / F-153 / LEDGER OQ-3. N-6's classifier returned
 * `ReceivableClearing` for every purchase order at every live status, and said
 * so out loud: its own docblock called the row "EXPLICITLY WRONG", kept only
 * because it was live and refusing it was outside that lane's scope (named
 * residual R-1). What it actually books is a NEGATIVE CUSTOMER receivable
 * (Cr 411) against a SUPPLIER partner — wrong account, wrong direction, wrong
 * partner class. A genuine supplier prepayment is Dr 409 and is a future
 * program; until then this is refused, not approximated.
 *
 * Every path is asserted separately because a policy object is only worth what
 * the LEAST guarded caller does with it, and the auto (FIFO) path is guarded by
 * a SQL mirror in `PaymentAllocationService::getOpenInvoices()` rather than by
 * the classifier — two halves of one rule that can drift apart.
 */
final class PurchaseOrderAllocationRefusedTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('po-refused');
    }

    public function test_the_direct_payment_endpoint_refuses_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '500.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $po->id, 'amount' => '500.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $this->assertNothingWasWritten($po->id);
    }

    public function test_the_manual_allocation_preview_refuses_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/preview-allocation', [
            'partner_id' => $this->vendor->id,
            'payment_amount' => '500.000',
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $po->id, 'amount' => '500.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $this->assertNothingWasWritten($po->id);
    }

    public function test_the_manual_allocation_execute_refuses_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();
        $payment = $this->makeUnallocatedPayment('500.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $po->id, 'amount' => '500.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $this->assertNothingWasWritten($po->id);
    }

    /**
     * The AUTO path never REFUSES a purchase order — it must never OFFER one.
     * `getOpenInvoices()` is the SQL mirror of the classifier, and a mirror that
     * still listed purchase orders would hand the execute loop a document its
     * own policy object then throws on: a 422 raised on the server's OWN choice
     * of target, not on anything the operator asked for.
     */
    public function test_the_auto_allocation_preview_never_offers_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/preview-allocation', [
            'partner_id' => $this->vendor->id,
            'payment_amount' => '500.000',
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('data.allocations'));
        $this->assertNothingWasWritten($po->id);
    }

    public function test_the_auto_allocation_execute_writes_nothing_for_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();
        $payment = $this->makeUnallocatedPayment('500.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertNothingWasWritten($po->id);
    }

    public function test_the_split_payment_path_refuses_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();

        $response = $this->actingAs($this->user)->postJson("/api/v1/documents/{$po->id}/split-payment", [
            'splits' => [
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '250.000'],
                ['payment_method_id' => $this->paymentMethod->id, 'repository_id' => $this->repository->id, 'amount' => '250.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $this->assertNothingWasWritten($po->id);
    }

    public function test_the_apply_deposit_path_refuses_a_purchase_order(): void
    {
        $po = $this->purchaseOrder();
        $deposit = $this->makeUnallocatedDeposit('500.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson("/api/v1/payments/{$deposit->id}/apply-deposit", [
            'document_id' => $po->id,
            'amount' => '500.000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOCUMENT_NOT_ALLOCATABLE');
        $response->assertJsonPath('error.details.reason', 'purchase_order_wrong_direction');

        $this->assertNothingWasWritten($po->id);
    }

    private function purchaseOrder(): Document
    {
        return $this->makeDocument(
            DocumentType::PurchaseOrder,
            DocumentStatus::Confirmed,
            '500.000',
            $this->vendor,
            'PO',
        );
    }

    /**
     * A refusal that still wrote a row, or still posted a journal entry, is not
     * a refusal.
     */
    private function assertNothingWasWritten(string $documentId): void
    {
        $this->assertSame(
            0,
            PaymentAllocation::query()->where('document_id', $documentId)->count(),
            'no allocation row may exist for a refused document',
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $documentId)->count(),
            'no journal entry may exist for a refused document',
        );
    }
}
