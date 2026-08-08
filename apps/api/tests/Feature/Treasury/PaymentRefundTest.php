<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PaymentRefundTest - Regression tests for payment refund workflows
 *
 * Tests covered:
 * - Full payment refund reverses allocation
 * - Partial refund maintains remaining balance
 * - Refund creates negative payment record
 * - Cannot refund more than original amount
 * - Cannot refund already reversed payment
 * - Get refund history for a payment
 * - Reverse payment writes a reversing document and nets allocations to zero
 *   (DPA V4 — it no longer deletes them)
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private Document $invoice;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.allocate']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0001',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'currency' => 'EUR',
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * Helper to create a payment with allocation
     */
    private function createPaymentWithAllocation(string $amount): Payment
    {
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-'.uniqid(),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $this->invoice->id,
            'amount' => $amount,
        ]);

        return $payment;
    }

    public function test_full_payment_refund_creates_negative_payment(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $refund = $this->refundService->refundPayment(
            $payment,
            'Customer requested refund',
            $this->user->id
        );

        // Refund should be a negative amount
        $this->assertEquals('-500.000', $refund->amount);
        $this->assertEquals(PaymentStatus::Completed, $refund->status);
        $this->assertStringContainsString('Refund for payment', $refund->reference);
        $this->assertStringContainsString('Refund: Customer requested refund', $refund->notes);

        // Original payment should be marked as reversed
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
    }

    public function test_full_refund_reverses_allocation(): void
    {
        $invoice = $this->makeInvoice('500.00', '0.00');
        $invoice->update(['status' => DocumentStatus::Paid]);
        $payment = $this->createPaymentAllocatedTo($invoice, '500.00');

        $refund = $this->refundService->refundPayment(
            $payment,
            'Reversal',
            $this->user->id
        );

        // Refund should have a negative allocation matching original
        $refundAllocations = $refund->allocations;
        $this->assertCount(1, $refundAllocations);
        $this->assertEquals('-500.000', $refundAllocations->first()->amount);
        $this->assertEquals($invoice->id, $refundAllocations->first()->document_id);

        // N1 fix (re-gate finding, 2026-08-02): recomputeDocumentBalances()
        // was wired into reversePayment() and unwindAllocationsProRata()
        // (partialRefund()'s path) but NOT into refundPayment() — the exact
        // I2 defect (balance_due reopens via the negative-allocation-row +
        // Postgres trigger, but the cached document `status` column never
        // reverts Paid -> Posted) on the MOST COMMON refund path.
        $invoice->refresh();
        $this->assertEquals(
            '500.000',
            $invoice->balance_due,
            'a full refund must restore balance_due to the full total'
        );
        $this->assertEquals(
            DocumentStatus::Posted,
            $invoice->status,
            'a full refund must revert Paid -> Posted, mirroring OutboundInstrumentService::cancel()'
        );
    }

    public function test_partial_refund_maintains_remaining_balance(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        $refund = $this->refundService->partialRefund(
            $payment,
            '300.00',
            'Partial product return',
            $this->user->id
        );

        // Refund should be negative partial amount
        $this->assertEquals('-300.000', $refund->amount);
        $this->assertEquals(PaymentStatus::Completed, $refund->status);

        // Original payment should NOT be marked as reversed (partial refund)
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Completed, $payment->status);
        $this->assertStringContainsString('Partial refund of 300.00', $payment->notes);
    }

    /**
     * MTP-TRE-10 regression: PaymentRefundService::partialRefund() used to
     * reverse only the GL/cash leg — it never touched PaymentAllocation or
     * the document's balance_due, unlike refundPayment() (the full-refund
     * path). A 250.00 partial refund of a payment that fully settled a
     * 600.00 invoice left that invoice's balance_due at 0.00 forever —
     * receivables permanently understated for any amount actually handed
     * back to the customer.
     */
    public function test_partial_refund_unwinds_allocation_and_reopens_document_balance(): void
    {
        $invoice = $this->makeInvoice('600.00', '0.00');
        $invoice->update(['status' => DocumentStatus::Paid]);
        $payment = $this->createPaymentAllocatedTo($invoice, '600.00');

        $refund = $this->refundService->partialRefund(
            $payment,
            '250.00',
            'Partial product return',
            $this->user->id
        );

        $this->assertEquals('-250.000', $refund->amount);

        $refundAllocation = PaymentAllocation::where('payment_id', $refund->id)->sole();
        $this->assertEquals($invoice->id, $refundAllocation->document_id);
        $this->assertEquals('-250.000', $refundAllocation->amount);

        $invoice->refresh();
        $this->assertEquals(
            '250.000',
            $invoice->balance_due,
            'the refunded amount must reopen the invoice balance_due (600.00 - 600.00 + 250.00)'
        );
        // Review finding I2: OutboundInstrumentService::cancel() (the
        // implementer's own cited mirror) ALSO reverts Paid -> Posted when
        // balance_due > 0 — reopening the cached balance alone still left
        // the document excluded from AgedReceivablesService, the
        // outstanding-document lookup, and the partial index that all
        // filter on status = Posted.
        $this->assertEquals(DocumentStatus::Posted, $invoice->status);
    }

    /**
     * Review finding C6, as a NET-MIRROR invariant (DPA V4 / T11).
     *
     * The C6 defect: reversePayment() used to delete only the ORIGINAL payment's
     * own PaymentAllocation rows, while unwindAllocationsProRata() writes its
     * negative rows against the REFUND payment's id — so a surviving -400.00 row
     * plus a wiped original allocation set made the Postgres balance_due-cache
     * trigger compute `1000.00 - (-400.00) = 1400.00`, ABOVE the invoice total.
     * The 2026-08-02 remediation fixed it by widening the DELETION to the whole
     * lineage.
     *
     * V4 removes the deletion entirely: the reversal writes a reversing DOCUMENT
     * whose negative mirror is sized on the NET of the lineage (600, not the
     * gross 1000). Every row in the lineage SURVIVES — the original's +1000, the
     * refund child's -400, and the reversal's -600 — and they sum to exactly 0.
     *
     * The C6 invariant is therefore STRENGTHENED rather than removed: the
     * `balance_due == 1000.000` assertion is the same canary it always was, and a
     * `SUM(payment_allocations) == 0` assertion is added beside it because
     * `balance_due` alone cannot detect rounding dust on SQLite (Document.php
     * evaluates the fallback arithmetic in floating point).
     */
    public function test_reverse_after_partial_refund_mirrors_the_net_lineage_and_keeps_every_row(): void
    {
        $invoice = $this->makeInvoice('1000.00', '0.00');
        $invoice->update(['status' => DocumentStatus::Paid]);
        $payment = $this->createPaymentAllocatedTo($invoice, '1000.00');

        $refund = $this->refundService->partialRefund(
            $payment,
            '400.00',
            'Partial return before reverse',
            $this->user->id
        );

        $invoice->refresh();
        $this->assertEquals('400.000', $invoice->balance_due);
        $this->assertEquals(DocumentStatus::Posted, $invoice->status);

        // reverse the ORIGINAL payment — partialRefund() never flips its
        // status, so it is still Completed and reversePayment() accepts it.
        $payment->refresh();
        $reversal = $this->refundService->reversePayment($payment, 'Reverse after partial refund', $this->user->id);

        $this->assertInstanceOf(Payment::class, $reversal);
        $this->assertSame(
            '-600.000',
            $reversal->amount,
            'the reversal is sized on the NET of the lineage (1000 - 400), never the gross'
        );
        $this->assertSame(
            1,
            PaymentAllocation::where('payment_id', $payment->id)->count(),
            'the original payment KEEPS its own allocation row — nothing is deleted'
        );
        $this->assertSame(
            1,
            PaymentAllocation::where('payment_id', $refund->id)->count(),
            'the refund child KEEPS its negative allocation row'
        );
        $this->assertSame(
            '-600.000',
            PaymentAllocation::where('payment_id', $reversal->id)->sole()->amount,
            'the reversal mirrors the NET, so the three rows sum to zero'
        );
        $this->assertSame(
            3,
            PaymentAllocation::where('document_id', $invoice->id)->count(),
            'three rows survive: +1000 original, -400 refund, -600 reversal'
        );
        $this->assertSame(
            0,
            bccomp(
                PaymentAllocation::where('document_id', $invoice->id)
                    ->get('amount')
                    ->reduce(
                        static fn (string $carry, PaymentAllocation $row): string => bcadd($carry, (string) $row->amount, 3),
                        '0'
                    ),
                '0',
                3
            ),
            'the invariant that replaces the wipe: SUM(payment_allocations) is exactly 0'
        );

        $invoice->refresh();
        $this->assertEquals(
            '1000.000',
            $invoice->balance_due,
            'reopens to the FULL total — never above it (C6 double-counting bug)'
        );
        $this->assertEquals(DocumentStatus::Posted, $invoice->status);

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
    }

    /**
     * MTP-TRE-10 fix, multi-document case: a single payment allocated across
     * two invoices must have its partial refund unwound PRO-RATA across
     * both, not dumped onto one.
     */
    public function test_partial_refund_unwinds_allocation_pro_rata_across_multiple_documents(): void
    {
        $invA = $this->makeInvoice('600.00', '0.00');
        $invB = $this->makeInvoice('400.00', '0.00');

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '1000.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-'.uniqid(),
            'created_by' => $this->user->id,
        ]);
        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $invA->id,
            'amount' => '600.00',
        ]);
        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $invB->id,
            'amount' => '400.00',
        ]);

        $this->refundService->partialRefund($payment, '250.00', 'Pro-rata partial refund', $this->user->id);

        // Pro-rata: invA share = 250 * (600/1000) = 150.000; invB share = 250 * (400/1000) = 100.000
        $invA->refresh();
        $invB->refresh();
        $this->assertEquals('150.000', $invA->balance_due, 'INV-A pro-rata share reopened');
        $this->assertEquals('100.000', $invB->balance_due, 'INV-B pro-rata share reopened');
    }

    /**
     * MTP-TRE-10 fix, repeated-refund case: two partial refunds of the same
     * payment must never unwind more than what is still live-allocated to
     * the document (400.00 + 300.00 = 700.00 <= the 1000.00 originally
     * allocated).
     */
    public function test_repeated_partial_refunds_accumulate_the_reopened_balance(): void
    {
        $invoice = $this->makeInvoice('1000.00', '0.00');
        $payment = $this->createPaymentAllocatedTo($invoice, '1000.00');

        $this->refundService->partialRefund($payment, '400.00', 'First return', $this->user->id);
        $invoice->refresh();
        $this->assertEquals('400.000', $invoice->balance_due);

        $this->refundService->partialRefund($payment, '300.00', 'Second return', $this->user->id);
        $invoice->refresh();
        $this->assertEquals('700.000', $invoice->balance_due);
    }

    private function makeInvoice(string $total, string $balanceDue): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => $balanceDue,
            'currency' => 'EUR',
        ]);
    }

    private function createPaymentAllocatedTo(Document $document, string $amount): Payment
    {
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-'.uniqid(),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $document->id,
            'amount' => $amount,
        ]);

        return $payment;
    }

    public function test_cannot_refund_more_than_original_amount(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed original payment amount');

        $this->refundService->partialRefund(
            $payment,
            '600.00',
            'Excessive refund attempt',
            $this->user->id
        );
    }

    public function test_refund_is_idempotent_for_reversed_payment(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // First refund succeeds
        $firstRefund = $this->refundService->refundPayment($payment, 'First refund', $this->user->id);

        // Second attempt should be idempotent - return the same refund
        $payment->refresh();
        $secondRefund = $this->refundService->refundPayment($payment, 'Second refund attempt', $this->user->id);

        // Should return the same refund payment
        $this->assertEquals($firstRefund->id, $secondRefund->id);
        $this->assertEquals('-500.000', $secondRefund->amount);
    }

    public function test_partial_refund_amount_must_be_positive(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero');

        $this->refundService->partialRefund(
            $payment,
            '0.00',
            'Zero refund',
            $this->user->id
        );
    }

    public function test_can_refund_check(): void
    {
        $completedPayment = $this->createPaymentWithAllocation('500.00');

        $this->assertTrue($this->refundService->canRefund($completedPayment));

        // Refund it
        $this->refundService->refundPayment($completedPayment, 'Test', $this->user->id);
        $completedPayment->refresh();

        // Now it should not be refundable
        $this->assertFalse($this->refundService->canRefund($completedPayment));
    }

    public function test_get_refund_history(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        // Make two partial refunds
        $this->refundService->partialRefund($payment, '200.00', 'First return', $this->user->id);
        $this->refundService->partialRefund($payment, '300.00', 'Second return', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('1000.000', $history['original_amount']);
        $this->assertEquals('500.00', $history['total_refunded']);
        $this->assertEquals('500.00', $history['remaining_amount']);
        $this->assertFalse($history['is_fully_refunded']);
        $this->assertEquals(2, $history['refund_count']);
    }

    public function test_refund_history_shows_fully_refunded(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // Full refund
        $this->refundService->refundPayment($payment, 'Full refund', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('500.00', $history['total_refunded']);
        $this->assertEquals('0.00', $history['remaining_amount']);
        $this->assertTrue($history['is_fully_refunded']);
    }

    /**
     * DPA V4 / T11 — was `test_reverse_payment_deletes_allocations()`.
     *
     * The deletion this test used to pin is exactly the document-per-action
     * violation V4 removes: a stock/GL/cash mutation with no justifying document.
     * The assertion it pinned is replaced, not dropped — the original row must
     * SURVIVE, and the reversal's negative mirror must net the document to zero.
     */
    public function test_reverse_payment_writes_a_reversing_document_and_nets_allocations_to_zero(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // Verify allocation exists
        $this->assertCount(1, $payment->allocations);

        // Reverse the payment
        $reversal = $this->refundService->reversePayment($payment, 'Data entry error', $this->user->id);

        // Payment should be reversed
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
        $this->assertStringContainsString('Reversed: Data entry error', $payment->notes);

        // The original's allocation SURVIVES; the reversing document carries the
        // offsetting negative mirror.
        $this->assertInstanceOf(Payment::class, $reversal);
        $this->assertCount(1, PaymentAllocation::where('payment_id', $payment->id)->get());
        $this->assertSame(
            '-500.000',
            PaymentAllocation::where('payment_id', $reversal->id)->sole()->amount
        );
        $this->assertSame(
            0,
            bccomp(
                PaymentAllocation::where('document_id', $this->invoice->id)
                    ->get('amount')
                    ->reduce(
                        static fn (string $carry, PaymentAllocation $row): string => bcadd($carry, (string) $row->amount, 3),
                        '0'
                    ),
                '0',
                3
            ),
            'SUM(payment_allocations) over the document is exactly 0'
        );

        $this->invoice->refresh();
        $this->assertEquals(
            $this->invoice->total,
            $this->invoice->balance_due,
            'balance_due equals the document total once the lineage nets to zero'
        );
    }

    public function test_reverse_payment_is_idempotent(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // First reversal succeeds
        $first = $this->refundService->reversePayment($payment, 'First reversal', $this->user->id);

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);

        // Second attempt is idempotent — it returns the SAME reversing document
        // and writes no second one (DPA V4 / D-7: at most one reversal per
        // original payment, enforced by a partial unique index as well).
        $second = $this->refundService->reversePayment($payment, 'Second attempt', $this->user->id);

        $this->assertInstanceOf(Payment::class, $first);
        $this->assertInstanceOf(Payment::class, $second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            Payment::where('original_payment_id', $payment->id)
                ->where('payment_type', PaymentType::Reversal->value)
                ->count()
        );

        // Payment should still be reversed (no change)
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
    }

    public function test_multiple_partial_refunds_accumulate(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        // Multiple small refunds
        $this->refundService->partialRefund($payment, '100.00', 'Refund 1', $this->user->id);
        $this->refundService->partialRefund($payment, '150.00', 'Refund 2', $this->user->id);
        $this->refundService->partialRefund($payment, '200.00', 'Refund 3', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('450.00', $history['total_refunded']);
        $this->assertEquals('550.00', $history['remaining_amount']);
        $this->assertEquals(3, $history['refund_count']);
    }
}
