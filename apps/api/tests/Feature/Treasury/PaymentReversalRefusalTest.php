<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
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
use App\Modules\Treasury\Domain\Enums\ReversalSupport;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V4 / T8 — the fail-closed `payment_type` refusals (plan D-6).
 *
 * This is a DELIBERATE NARROWING of a currently-permissive endpoint. Today every
 * payment shape "works": the reversal silently deletes allocations and posts zero
 * GL. Under V4, only shapes whose reversing GL shape is actually correct proceed;
 * the rest refuse. `createPaymentRefundJournalEntry()` is AR-shaped
 * (Dr CustomerReceivable / Cr cash), which is right only for `DocumentPayment` —
 * an advance credits the customer-advance account, a supplier payment has the
 * wrong direction AND accounts, and POS posts direct to revenue with no AR at
 * all. Posting that shape unconditionally would upgrade "silently zero" to
 * "silently wrong", which is worse.
 *
 * Owner-approved for `POS`, `SupplierPayment`, `Refund`, `Reversal` (and, in the
 * orthogonal instrument gate, `Cleared` — see DeferredTenderGuardsTest).
 *
 * **DPA `DPA-REV2-A` (A7): `Advance` is NO LONGER REFUSED and was removed from
 * the provider above.** OQ-B is closed. The refusal existed because
 * `createPaymentRefundJournalEntry()` is AR-shaped and an advance credits
 * `CustomerAdvance`, so "silently zero" beat "silently wrong". That premise is
 * gone: the reversing shape now comes from the payment's posted ledger footprint
 * (`PaymentLedgerPartitionReader`), and the customer-advance reversing entry
 * exists (`GeneralLedgerService::reverseCustomerAdvanceJournalEntry()`), so an
 * advance reverses CORRECTLY rather than being refused. The success path is
 * covered by `AdvanceReversalGlShapeTest`, which asserts on posted journal lines.
 *
 * The four remaining refusals are refused on DIRECTION and LANE grounds, not on
 * account-shape grounds — see `PaymentType::reversalSupport()`.
 */
final class PaymentReversalRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $partner;

    private Document $invoice;

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
     * Gate r1: the annotation said `list<...>` while the provider has always been
     * STRING-KEYED (the keys are the data-set names PHPUnit prints on failure).
     * Corrected rather than propagated.
     *
     * @return array<string, array{PaymentType, string}>
     */
    public static function unsupportedShapes(): array
    {
        return [
            'supplier payment' => [PaymentType::SupplierPayment, 'supplier refund lane'],
            'pos' => [PaymentType::POS, 'pos void/return lane'],
            // W4R2-2: the new case must refuse for the same reason `POS` does —
            // `reversalSupport()` answers `Unsupported` for both, and both share
            // the refusal message arm in `unsupportedReversalMessage()`.
            'pos refund' => [PaymentType::POSRefund, 'pos void/return lane'],
            'refund row' => [PaymentType::Refund, 'cannot itself be reversed'],
            'reversal row' => [PaymentType::Reversal, 'cannot itself be reversed'],
        ];
    }

    #[DataProvider('unsupportedShapes')]
    public function test_an_unsupported_payment_shape_refuses_and_writes_nothing(
        PaymentType $type,
        string $expectedFragment,
    ): void {
        $payment = $this->paymentOfType($type);

        $paymentsBefore = Payment::query()->count();
        $allocationsBefore = PaymentAllocation::query()->count();
        // The `reversal row` data set seeds a Reversal-typed row itself, so the
        // assertion below is "no NEW reversing document", not "none at all".
        $reversalsBefore = Payment::query()->where('payment_type', PaymentType::Reversal->value)->count();

        try {
            $this->refundService->reversePayment($payment, 'must refuse', $this->user->id);
            self::fail("{$type->value} must refuse reversal");
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                $expectedFragment,
                strtolower($exception->getMessage()),
                "{$type->value}: the refusal must name the correct lane, or honestly name its absence",
            );
        }

        self::assertSame($paymentsBefore, Payment::query()->count(), 'no payment row written');
        self::assertSame($allocationsBefore, PaymentAllocation::query()->count(), 'no allocation written');
        self::assertSame(
            PaymentStatus::Completed,
            $payment->fresh()?->status,
            'the original payment must stay Completed',
        );
        self::assertSame(
            $reversalsBefore,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
            'no reversing document was written',
        );
        $this->assertDatabaseCount('repository_movements', 0);
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
        );

        // The invoice keeps its allocation untouched: a refusal is a no-op.
        self::assertSame(
            1,
            PaymentAllocation::query()->where('document_id', $this->invoice->id)->count(),
        );
    }

    /**
     * W4R2-2 gate r1 [Important] — the OUT-OF-TILE consequence of the backfill,
     * pinned rather than left implicit. **Ruled correct by the orchestrator**: the
     * old `document_payment` tag was a MIS-TAG, and a supplier payment is not
     * cash-reversible through this lane.
     *
     * `payment_type` is an input to reversal eligibility:
     * `PaymentType::reversalSupport()` answers `CashReversal` for
     * `DocumentPayment` but `Unsupported` for `SupplierPayment`. So a historical
     * supplier payment that `reversePayment()` would have ACCEPTED before the
     * migration is REFUSED after it. That is a user-visible behaviour change on
     * existing data produced by a lane scoped "tile-only", and it is a net
     * improvement: for a supplier payment `PaymentLedgerPartitionReader::read()`
     * returns an EMPTY partition (it sums only customer_payment / advance /
     * payment_advance_reclass source types), and an empty partition falls back to
     * the legacy single AR restoration — i.e. the accepted path would have posted
     * an AR-restoring entry for money that never touched 411. Retyping closes
     * that hole; this test is the pin that says so out loud.
     *
     * The row is moved by the REAL migration, from REAL arm-(a1) evidence (a
     * posted `supplier_payment` entry with a Dr on the 401 account), so the test
     * fails if either the predicate or the refusal drifts.
     */
    public function test_a_backfilled_supplier_payment_is_refused_by_the_reversal_lane(): void
    {
        $payment = $this->paymentOfType(PaymentType::DocumentPayment);

        // Before the backfill this shape is reversal-ELIGIBLE — the very thing
        // that changes. Asserted on the enum so the test states the delta rather
        // than implying it.
        self::assertNotSame(
            ReversalSupport::Unsupported,
            $payment->payment_type->reversalSupport(),
        );

        $this->postSupplierPaymentEvidenceFor($payment);

        $backfill = require base_path(
            'database/migrations/tenant/2026_08_25_150100_retype_supplier_and_pos_refund_payments.php'
        );
        $backfill->up();

        $payment->refresh();
        self::assertSame(PaymentType::SupplierPayment, $payment->payment_type);

        $paymentsBefore = Payment::query()->count();
        $allocationsBefore = PaymentAllocation::query()->count();

        try {
            $this->refundService->reversePayment($payment, 'must refuse after backfill', $this->user->id);
            self::fail('a backfilled supplier payment must refuse reversal');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'supplier refund lane',
                strtolower($exception->getMessage()),
                'the refusal must name the supplier lane (VendorRefundService), not fail generically',
            );
        }

        // A refusal is a no-op: nothing written, nothing unwound.
        self::assertSame($paymentsBefore, Payment::query()->count());
        self::assertSame($allocationsBefore, PaymentAllocation::query()->count());
        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
    }

    /**
     * Arm-(a1) evidence for the backfill: a POSTED `supplier_payment` journal
     * entry naming this payment, carrying a debit on the company's 401.
     */
    private function postSupplierPaymentEvidenceFor(Payment $payment): void
    {
        $entry = JournalEntry::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.Str::random(10),
            'entry_date' => now(),
            'description' => 'Supplier payment (backfill evidence)',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'source_type' => 'supplier_payment',
            'source_id' => $payment->id,
        ]);

        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => Account::findByPurposeOrFail(
                $this->company->id,
                SystemAccountPurpose::SupplierPayable,
            )->id,
            'partner_id' => $this->partner->id,
            'debit' => '500.00',
            'credit' => '0',
            'description' => 'Dr 401',
        ]);
    }

    /**
     * The refusals surface as the controller's existing 422 rather than a 500 —
     * `PaymentRefundController::reversePayment()` catches `\Exception`.
     */
    public function test_a_refusal_surfaces_as_a_422_over_the_http_api(): void
    {
        $this->user->givePermissionTo('payments.reverse');
        $payment = $this->paymentOfType(PaymentType::POS);

        $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/reverse", ['reason' => 'http refusal'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $error): bool => str_contains(strtolower($error), 'pos'));

        self::assertSame(PaymentStatus::Completed, $payment->fresh()?->status);
    }

    private function paymentOfType(PaymentType $type): Payment
    {
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->cashMethod->id,
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
