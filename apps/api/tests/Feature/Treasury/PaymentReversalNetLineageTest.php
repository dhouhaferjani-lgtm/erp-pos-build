<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
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
 * DPA V4 / T4 — `netLiveAllocationsByDocument()`, the NET-of-refund-lineage
 * allocation map that the reversing document mirrors.
 *
 * Plan D-3 (the riskiest decision in the lane): mirroring the ORIGINAL's
 * allocations gross would resurrect the C6 defect in a new shape —
 * `balance_due` ABOVE the document total. The reversal must mirror the NET of
 * the whole lineage (the original's rows plus every refund child's negative
 * rows).
 *
 * D-3b: every NON-ZERO net is returned, negatives included. A negative net is
 * reachable at dust scale through the uncapped last pro-rata slice; dropping it
 * would leave a residual negative row behind, which `Document::OUTSTANDING_
 * BALANCE_SQL` (and the PG balance_due trigger) turn into `total + dust`.
 *
 * The helper is private by design (it is an internal invariant of
 * `PaymentRefundService`, not an API); reflection is used deliberately so the
 * plan's per-figure assertions can be made directly rather than inferred from
 * downstream balances.
 */
final class PaymentReversalNetLineageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    public function test_net_is_the_unrefunded_remainder_after_a_partial_refund(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        $this->refundService->partialRefund($payment, '400.00', 'partial', $this->user->id);

        self::assertSame(
            [$invoice->id => '600.000'],
            $this->netMap($payment->fresh() ?? $payment),
            'gross would be 1000 and would drive balance_due to 1400 (the C6 defect)',
        );
    }

    public function test_an_exactly_zero_net_is_skipped(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        // Two partial refunds totalling the full amount: the original stays
        // Completed (partialRefund never flips the status), so this is D-4's
        // zero-net reversal fixture.
        $this->refundService->partialRefund($payment, '600.00', 'first', $this->user->id);
        $this->refundService->partialRefund($payment, '400.00', 'second', $this->user->id);

        self::assertSame(
            [],
            $this->netMap($payment->fresh() ?? $payment),
            'a document that nets to exactly zero needs no mirror row',
        );
    }

    public function test_multi_document_nets_are_reported_per_document(): void
    {
        $invA = $this->invoice('600.00');
        $invB = $this->invoice('400.00');
        $payment = $this->paymentAllocatedTo([[$invA, '600.00'], [$invB, '400.00']], '1000.00');

        // Pro-rata: A gives up 150.000, B gives up 100.000.
        $this->refundService->partialRefund($payment, '250.00', 'pro-rata', $this->user->id);

        $map = $this->netMap($payment->fresh() ?? $payment);
        self::assertSame('450.000', $map[$invA->id], '600 allocated - 150 unwound');
        self::assertSame('300.000', $map[$invB->id], '400 allocated - 100 unwound');
        self::assertCount(2, $map);
    }

    /**
     * D-3b: a NEGATIVE lineage net must be RETURNED, not dropped. Built here by
     * hand (an over-unwinding refund-child row) rather than through the rounding
     * path, so the assertion holds independently of T13's fix.
     */
    public function test_a_negative_net_is_returned_not_dropped(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->paymentAllocatedTo([[$invoice, '100.00']], '100.00');

        $refundChild = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '-100.001',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Refund,
            'original_payment_id' => $payment->id,
            'reference' => 'Refund for payment '.$payment->reference,
        ]);
        PaymentAllocation::query()->create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $refundChild->id,
            'document_id' => $invoice->id,
            'amount' => '-100.001',
        ]);

        self::assertSame(
            [$invoice->id => '-0.001'],
            $this->netMap($payment),
            'a negative net must be mirrored as a POSITIVE row so the lineage sums to exactly zero',
        );
    }

    /**
     * Plan I8 / gate Important-8: the `$excludePaymentId` parameter is mandatory,
     * not polish — `unwindAllocationsProRata()`'s prior-refund scan excludes the
     * IN-FLIGHT refund row, and a helper without the parameter would silently
     * change that predicate.
     */
    public function test_exclude_payment_id_removes_that_row_from_the_lineage(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        $refund = $this->refundService->partialRefund($payment, '400.00', 'partial', $this->user->id);

        self::assertSame(
            [$invoice->id => '1000.000'],
            $this->netMap($payment->fresh() ?? $payment, $refund->id),
            'excluding the refund child must leave only the original allocation',
        );
    }

    /**
     * @return array<string, string>
     */
    private function netMap(Payment $original, ?string $excludePaymentId = null): array
    {
        $method = new \ReflectionMethod(PaymentRefundService::class, 'netLiveAllocationsByDocument');

        /** @var array<string, string> $result */
        $result = $method->invoke($this->refundService, $original, 3, $excludePaymentId);

        return $result;
    }

    private function invoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => '0.00',
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  list<array{0: Document, 1: string}>  $allocations
     */
    private function paymentAllocatedTo(array $allocations, string $amount): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);

        foreach ($allocations as [$document, $allocated]) {
            PaymentAllocation::query()->create([
                'id' => Str::uuid()->toString(),
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'amount' => $allocated,
            ]);
        }

        return $payment;
    }
}
