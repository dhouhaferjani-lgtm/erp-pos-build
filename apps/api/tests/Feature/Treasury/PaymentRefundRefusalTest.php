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
use App\Modules\Treasury\Domain\Exceptions\RefundLaneRefusedException;
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
 * positive supplier payment sails through. The reversal lane already refuses the
 * supplier shape via `PaymentType::reversalSupport() === Unsupported`
 * (see PaymentReversalRefusalTest); this test pins the SAME refusal on both refund
 * entry points.
 *
 * SCOPE — fix round 1 (gate r1 findings #1 and #4). The first cut of this suite
 * also pinned POS / POSRefund as refused. That was scope creep beyond F-W2-13 (a
 * SUPPLIER finding) and it broke the §13 writer-inventory rows 7/8, which refund a
 * POS-typed payment through this very lane
 * (`tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:435`, `:471`).
 * Whether the back office may refund a POS payment is a second flow needing its own
 * owner ruling, so those cases are INVERTED here: `test_pos_shapes_are_still_offered`
 * pins that POS behaviour is exactly what it was before F-W2-13.
 *
 * `Refund` / `Reversal` rows are negative and are refused earlier by
 * `assertRefundableSubject()`; they are covered by PaymentRefundTest and are
 * deliberately NOT re-asserted here.
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

    public function test_full_refund_of_a_supplier_payment_refuses_and_writes_nothing(): void
    {
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        $this->assertRefusedAndNothingWritten(
            fn () => $this->refundService->refundPayment($payment, 'must refuse', $this->user->id),
            $payment,
        );
    }

    public function test_partial_refund_of_a_supplier_payment_refuses_and_writes_nothing(): void
    {
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        // 100 of 500 is comfortably within the per-request amount bounds, so the
        // request reaches (and must be stopped by) the payment_type gate, not the
        // incidental over-refund comparison.
        $this->assertRefusedAndNothingWritten(
            fn () => $this->refundService->partialRefund($payment, '100.00', 'must refuse', $this->user->id),
            $payment,
        );
    }

    public function test_can_refund_is_false_for_a_supplier_payment(): void
    {
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        self::assertFalse(
            $this->refundService->canRefund($payment),
            'supplier_payment: the UI must not offer a refund action for a shape this lane refuses',
        );
    }

    /**
     * The narrowing itself (gate r1 findings #1 / #4): the guard is SUPPLIER-only,
     * so a POS-typed payment is still offered exactly as before F-W2-13. The write
     * path for these rows is pinned by the §13 writer inventory
     * (`PaymentOriginWriterInventoryTest::test_refund_inherits_pos_origin_when_original_is_pos`
     * and its partial-refund twin), which this suite must not contradict.
     *
     * @return array<string, array{PaymentType}>
     */
    public static function posShapes(): array
    {
        return [
            'pos' => [PaymentType::POS],
            'pos refund' => [PaymentType::POSRefund],
        ];
    }

    #[DataProvider('posShapes')]
    public function test_pos_shapes_are_still_offered(PaymentType $type): void
    {
        $payment = $this->paymentOfType($type);

        self::assertTrue(
            $this->refundService->canRefund($payment),
            "{$type->value}: F-W2-13 is a supplier finding — POS behaviour must be unchanged",
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
     * Fix round 1 (gate r1 finding #2): the refusal must REACH the operator. The
     * body is the house envelope `{error:{code,message,details}}` — the shape
     * `apps/web/src/lib/api.ts` `getErrorMessage()` reads (`data.error.message`).
     * A bare `{"error": "<string>"}` renders as "Request failed with status code
     * 422" in the toast, which is why the string shape is asserted against here.
     */
    public function test_a_full_refund_refusal_surfaces_as_a_structured_422_over_the_http_api(): void
    {
        $this->user->givePermissionTo('payments.refund');
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'reason' => 'http refusal',
                'refund_request_id' => Str::uuid()->toString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REFUND_LANE_REFUSED')
            ->assertJsonPath('error.message', __('treasury.refund_refused.supplier_payment'))
            ->assertJsonPath('error.details.payment_type', PaymentType::SupplierPayment->value);

        /** @var array{error: array{message: string}} $body */
        $body = $response->json();
        self::assertStringNotContainsString(
            'VendorRefundService',
            $body['error']['message'],
            'gate r1 finding #3: the operator text must not name an internal class, '
            .'nor a lane that would refuse this payment too',
        );

        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
    }

    /**
     * The partial-refund entry point answers with the same envelope — it has its
     * own catch arm, and the first cut flattened both to a bare string.
     */
    public function test_a_partial_refund_refusal_surfaces_as_a_structured_422_over_the_http_api(): void
    {
        $this->user->givePermissionTo('payments.refund');
        $payment = $this->paymentOfType(PaymentType::SupplierPayment);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/partial-refund", [
                'amount' => '100.00',
                'reason' => 'http refusal',
                'refund_request_id' => Str::uuid()->toString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REFUND_LANE_REFUSED')
            ->assertJsonPath('error.message', __('treasury.refund_refused.supplier_payment'));

        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
    }

    /**
     * A refusal is a no-op: no payment row, no journal entry (so 401 is untouched
     * AND no 411 entry appears), no cash movement, and the original stays Completed.
     */
    private function assertRefusedAndNothingWritten(
        callable $act,
        Payment $payment,
    ): void {
        $paymentsBefore = Payment::query()->count();
        $entriesBefore = JournalEntry::query()->where('company_id', $this->company->id)->count();

        try {
            $act();
            self::fail('the refund lane must refuse this shape');
        } catch (RefundLaneRefusedException $exception) {
            // Typed, so the controller can answer with the house envelope instead
            // of a bare string (gate r1 finding #2).
            self::assertSame(PaymentType::SupplierPayment, $exception->paymentType);
            self::assertSame($payment->id, $exception->paymentId);
            self::assertStringContainsString(
                'supplier invoice',
                strtolower($exception->getMessage()),
                'the technical message must say WHY, on the payment it refused',
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
