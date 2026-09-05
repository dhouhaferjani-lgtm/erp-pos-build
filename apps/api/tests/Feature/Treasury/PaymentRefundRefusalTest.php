<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * F-W2-13 (P0) — the CUSTOMER refund lane must REFUSE a supplier payment.
 *
 * `refundPayment()` / `partialRefund()` post their GL UNCONDITIONALLY through the
 * AR-only shape `createPaymentRefundJournalEntry()` (Dr CustomerReceivable 411 /
 * Cr Bank, `source_type='customer_payment_refund'`) plus a cash movement OUT. That
 * is right ONLY for a genuine customer payment. A `SupplierPayment` (originally
 * Dr 401 / Cr Bank) undoes as Dr Bank / Cr 401 with cash IN — the opposite
 * direction and a different subledger. Refunding one through this lane debits 411
 * for a receivable that never existed, moves cash the wrong way, and never
 * re-credits 401.
 *
 * The subject guard `assertRefundableSubject()` only checks `amount > 0`, so a
 * positive supplier payment sails through. The reversal lane already refuses these
 * shapes via `PaymentType::reversalSupport() === Unsupported`
 * (see PaymentReversalRefusalTest); this test pins the SAME refusal on both refund
 * entry points. POS / POSRefund are `Unsupported` for the same lane reasons and
 * are refused here too.
 *
 * `Refund` / `Reversal` rows are ALSO `Unsupported`, but they are negative and are
 * already refused earlier by `assertRefundableSubject()`; they are covered by
 * PaymentRefundTest and are deliberately NOT re-asserted here.
 */
final class PaymentRefundRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $partner;

    private Document $invoice;

    private PaymentRepository $cashRegister;

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
        // A GL-linked till so the refund lane actually REACHES the AR journal +
        // cash-movement writes (postRefundGlAndMovement() no-ops without one). This
        // is what makes "no 411 entry, no cash movement" a meaningful assertion:
        // without the fix, refunding a supplier payment posts BOTH.
        $this->cashRegister = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Main Cash Register',
            'type' => RepositoryType::CashRegister,
            'is_active' => true,
            'balance' => '5000.00',
            'gl_account_id' => Account::findByPurposeOrFail(
                $this->company->id,
                SystemAccountPurpose::Bank,
            )->id,
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->invoice = Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'partner_id' => $this->partner->id,
            'document_date' => now(),
            'status' => DocumentStatus::Paid,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '0.00',
            'currency' => 'EUR',
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * The POSITIVE `Unsupported` shapes that actually reach the refund lane (the
     * negative Refund/Reversal rows are stopped earlier by the amount guard).
     *
     * @return array<string, array{PaymentType, string}>
     */
    public static function unsupportedRefundShapes(): array
    {
        return [
            'supplier payment' => [PaymentType::SupplierPayment, 'supplier refund lane'],
            'pos' => [PaymentType::POS, 'pos void/return lane'],
            'pos refund' => [PaymentType::POSRefund, 'pos void/return lane'],
        ];
    }

    #[DataProvider('unsupportedRefundShapes')]
    public function test_full_refund_of_an_unsupported_shape_refuses_and_writes_nothing(
        PaymentType $type,
        string $expectedFragment,
    ): void {
        $payment = $this->paymentOfType($type);

        $this->assertRefusedAndNothingWritten(
            fn () => $this->refundService->refundPayment($payment, 'must refuse', $this->user->id),
            $payment,
            $expectedFragment,
        );
    }

    #[DataProvider('unsupportedRefundShapes')]
    public function test_partial_refund_of_an_unsupported_shape_refuses_and_writes_nothing(
        PaymentType $type,
        string $expectedFragment,
    ): void {
        $payment = $this->paymentOfType($type);

        // 100 of 500 is comfortably within the per-request amount bounds, so the
        // request reaches (and must be stopped by) the payment_type gate, not the
        // incidental over-refund comparison.
        $this->assertRefusedAndNothingWritten(
            fn () => $this->refundService->partialRefund($payment, '100.00', 'must refuse', $this->user->id),
            $payment,
            $expectedFragment,
        );
    }

    #[DataProvider('unsupportedRefundShapes')]
    public function test_can_refund_is_false_for_an_unsupported_shape(PaymentType $type): void
    {
        $payment = $this->paymentOfType($type);

        self::assertFalse(
            $this->refundService->canRefund($payment),
            "{$type->value}: the UI must not offer a refund action for a shape this lane refuses",
        );
    }

    /**
     * Regression: a genuine customer payment still refunds unchanged, AR entry and
     * all. The refusal narrows ONLY the wrong-lane shapes.
     */
    public function test_a_genuine_customer_payment_still_refunds(): void
    {
        $payment = $this->paymentOfType(PaymentType::DocumentPayment);

        self::assertTrue($this->refundService->canRefund($payment));

        $refund = $this->refundService->refundPayment($payment, 'customer requested', $this->user->id);

        self::assertSame('-500.000', $refund->amount);
        self::assertSame(PaymentStatus::Reversed, $payment->fresh()?->status);
        self::assertSame(
            1,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
            'the AR refund entry IS posted for a genuine customer payment',
        );
    }

    /**
     * The refusal surfaces as the controller's existing 422 (it catches
     * `\Exception`), naming the correct vendor-refund path.
     */
    public function test_a_full_refund_refusal_surfaces_as_a_422_over_the_http_api(): void
    {
        $this->user->givePermissionTo('payments.refund');
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'reason' => 'http refusal',
                'refund_request_id' => Str::uuid()->toString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error',
                fn (string $error): bool => str_contains(strtolower($error), 'supplier refund lane'),
            );

        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
    }

    /**
     * A refusal is a no-op: no payment row, no journal entry (so 401 is untouched
     * AND no 411 entry appears), no cash movement, and the original stays Completed.
     */
    private function assertRefusedAndNothingWritten(
        callable $act,
        Payment $payment,
        string $expectedFragment,
    ): void {
        $paymentsBefore = Payment::query()->count();
        $entriesBefore = JournalEntry::query()->where('company_id', $this->company->id)->count();

        try {
            $act();
            self::fail('the refund lane must refuse this shape');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                $expectedFragment,
                strtolower($exception->getMessage()),
                'the refusal must name the correct lane',
            );
        }

        self::assertSame($paymentsBefore, Payment::query()->count(), 'no refund payment row written');
        self::assertSame(
            PaymentStatus::Completed,
            $payment->fresh()?->status,
            'the original payment must stay Completed',
        );
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
            'no 411 customer-refund journal entry may be posted',
        );
        self::assertSame(
            $entriesBefore,
            JournalEntry::query()->where('company_id', $this->company->id)->count(),
            'no journal entry written at all — SupplierPayable(401) is untouched',
        );
        $this->assertDatabaseCount('repository_movements', 0);
    }

    private function paymentOfType(PaymentType $type): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->cashRegister->id,
            'amount' => '500.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => $type,
            'reference' => 'PMT-'.Str::random(8),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::query()->create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $this->invoice->id,
            'amount' => '500.00',
        ]);

        return $payment;
    }
}
