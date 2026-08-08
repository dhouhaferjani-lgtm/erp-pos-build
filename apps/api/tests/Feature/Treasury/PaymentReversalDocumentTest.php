<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
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
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V4 / T5 — the reversing DOCUMENT.
 *
 * `reversePayment()` no longer DELETES allocation rows. It writes a child
 * `Payment` of type `Reversal`, linked by `original_payment_id`, carrying the
 * NET unreversed amount as a negative value, and mirrors the per-document NET
 * lineage allocation as negative rows against that reversal payment. The
 * invariant that replaces the old wipe: for every touched document,
 * `SUM(payment_allocations.amount) == 0` and `balance_due == total`.
 *
 * The `SUM` assertion — not `balance_due` alone — is the one that catches
 * rounding dust: SQLite evaluates the `balance_due` fallback arithmetic in
 * floating point (`Document.php`), so a one-ulp residual can hide there.
 */
final class PaymentReversalDocumentTest extends TestCase
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

    public function test_reversal_writes_a_negative_child_document_and_keeps_the_original_allocation(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');

        $reversal = $this->refundService->reversePayment($original, 'Data entry error', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame(PaymentType::Reversal, $reversal->payment_type);
        self::assertSame($original->id, $reversal->original_payment_id);
        self::assertSame('-500.000', $reversal->amount);
        self::assertSame(PaymentStatus::Completed, $reversal->status);
        self::assertSame('EUR', $reversal->currency);
        self::assertStringContainsString('Reversal for payment', (string) $reversal->reference);
        self::assertStringContainsString('Reversal: Data entry error', (string) $reversal->notes);

        // The audit trail is PRESERVED, not deleted — that is the whole point.
        self::assertSame(
            1,
            PaymentAllocation::query()->where('payment_id', $original->id)->count(),
            'the original payment keeps its own allocation row',
        );

        $mirror = PaymentAllocation::query()->where('payment_id', $reversal->id)->sole();
        self::assertSame($invoice->id, $mirror->document_id);
        self::assertSame('-500.000', $mirror->amount);

        $this->assertDocumentNetsToZero($invoice);

        $original->refresh();
        self::assertSame(PaymentStatus::Reversed, $original->status);
        self::assertStringContainsString('Reversed: Data entry error', (string) $original->notes);
    }

    /**
     * Gate I-2 — the lane's headline invariant must hold when the ALLOCATION
     * storage scale exceeds the CURRENCY scale.
     *
     * `payment_allocations.amount` is `NUMERIC(15,3)`
     * (`2026_06_22_120000_widen_payment_allocations_amount_to_scale_3`), while EUR
     * resolves to currency scale 2. A 3-decimal allocation on a 2-decimal currency
     * is REACHABLE through the public API: `PaymentController.php:406` validates
     * `allocations.*.amount` with `regex:/^\d+(\.\d{1,3})?$/` and applies no
     * currency-scale narrowing.
     *
     * Summing those rows at currency scale would TRUNCATE the net, so the mirror
     * would under-restore: the lineage would sum to `+0.005` instead of `0`, and
     * `balance_due` would settle permanently BELOW the document total after a
     * "full" reversal — with the cash branch posting `Dr AR` at the truncated net,
     * so the subledger and the GL drift together. The direction is below the total,
     * so C6 itself is not violated, but "SUM == 0 by construction" would be false.
     *
     * The net map is therefore computed at the allocation storage scale.
     */
    public function test_a_three_decimal_allocation_on_a_two_decimal_currency_still_nets_to_zero(): void
    {
        // The DOCUMENT total stays at the currency scale — the case under test is
        // an allocation carrying more decimals than its currency, not a
        // sub-scale document total (which `recomputeDocumentBalances()` handles on
        // the shared refund path and which V4 does not touch).
        $invoice = $this->paidInvoice('500.01');
        $original = $this->paymentAllocatedTo([[$invoice, '500.005']], '500.01');

        $reversal = $this->refundService->reversePayment($original, 'sub-scale allocation', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);

        $mirror = PaymentAllocation::query()->where('payment_id', $reversal->id)->sole();
        self::assertSame(
            0,
            bccomp('-500.005', (string) $mirror->amount, 3),
            'the mirror must negate the stored allocation EXACTLY, not its currency-scale truncation',
        );

        $this->assertDocumentNetsToZero($invoice);
    }

    /** D-19: three fields must be deliberately EXCLUDED from the reversal row. */
    public function test_the_reversal_row_carries_none_of_the_three_excluded_fields(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');
        $original->update(['fiscal_event_id' => null]);

        $reversal = $this->refundService->reversePayment($original, 'exclusions', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertNull($reversal->instrument_id, 'D-19: never recreate the payment-2-points-at-payment-1 shape');
        self::assertNull($reversal->fiscal_event_id, 'D-19: DepositAllocationSummaryService does ->first() on this column');
        self::assertNull($reversal->refund_request_id, 'D-19: reversal idempotency is index-based, not request-id-based');
    }

    /**
     * The C6 scenario, as a REVERSAL invariant (plan D-3, worked example).
     *
     * Gross mirroring would give `SUM = 1000 - 400 - 1000 = -400` and therefore
     * `balance_due = 1400` — above the invoice total, with 1400 of cash leaving
     * against 1000 collected. The NET reversal (-600) lands `SUM` at exactly 0.
     */
    public function test_reversal_after_a_partial_refund_is_net_never_gross(): void
    {
        $invoice = $this->paidInvoice('1000.00');
        $original = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        $refund = $this->refundService->partialRefund($original, '400.00', 'partial', $this->user->id);

        $reversal = $this->refundService->reversePayment(
            $original->fresh() ?? $original,
            'reverse after partial refund',
            $this->user->id,
        );

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame('-600.000', $reversal->amount, 'NET of the refund lineage, never gross');

        $mirror = PaymentAllocation::query()->where('payment_id', $reversal->id)->sole();
        self::assertSame('-600.000', $mirror->amount);

        // THREE allocation rows survive: +1000 (original), -400 (refund child),
        // -600 (reversal). Under the old model all three were deleted.
        self::assertSame(
            3,
            PaymentAllocation::query()->where('document_id', $invoice->id)->count(),
        );
        self::assertSame(
            1,
            PaymentAllocation::query()->where('payment_id', $refund->id)->count(),
            'the refund child KEEPS its negative row — the lineage-wide wipe is gone',
        );

        $this->assertDocumentNetsToZero($invoice);
    }

    public function test_a_multi_document_reversal_nets_every_touched_document_to_zero(): void
    {
        $invA = $this->paidInvoice('600.00');
        $invB = $this->paidInvoice('400.00');
        $original = $this->paymentAllocatedTo([[$invA, '600.00'], [$invB, '400.00']], '1000.00');

        $this->refundService->partialRefund($original, '250.00', 'pro-rata', $this->user->id);

        $reversal = $this->refundService->reversePayment(
            $original->fresh() ?? $original,
            'multi-doc reverse',
            $this->user->id,
        );

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame('-750.000', $reversal->amount);

        $mirrors = PaymentAllocation::query()
            ->where('payment_id', $reversal->id)
            ->get()
            ->keyBy('document_id');
        self::assertCount(2, $mirrors);
        self::assertSame('-450.000', $mirrors[$invA->id]->amount);
        self::assertSame('-300.000', $mirrors[$invB->id]->amount);

        $this->assertDocumentNetsToZero($invA);
        $this->assertDocumentNetsToZero($invB);
    }

    public function test_reversal_is_idempotent_and_writes_exactly_one_reversing_document(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');

        $first = $this->refundService->reversePayment($original, 'first', $this->user->id);
        $second = $this->refundService->reversePayment($original->fresh() ?? $original, 'second', $this->user->id);

        self::assertInstanceOf(Payment::class, $first);
        self::assertInstanceOf(Payment::class, $second);
        self::assertSame($first->id, $second->id, 'the same reversing document is returned');

        self::assertSame(
            1,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
        );
        self::assertSame(
            1,
            PaymentAllocation::query()->where('payment_id', $first->id)->count(),
        );
        $this->assertDocumentNetsToZero($invoice);
    }

    /**
     * D-13 case 2 — status `Reversed` with NO reversal row in existence. Reached
     * by `refundPayment()`, which stamps the original `Reversed` itself. Today's
     * behaviour is a silent no-op and V4 preserves it byte-for-byte: return
     * `null`, write nothing, dispatch nothing. Turning it into a 422 would be a
     * regression for a legitimate, currently-successful caller path.
     *
     * No such test existed before V4.
     */
    public function test_a_payment_already_unwound_by_a_full_refund_returns_null_and_writes_nothing(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');

        $this->refundService->refundPayment($original, 'full refund', $this->user->id);
        $original->refresh();
        self::assertSame(PaymentStatus::Reversed, $original->status);

        $paymentsBefore = Payment::query()->count();
        $allocationsBefore = PaymentAllocation::query()->count();

        Event::fake([PaymentReversed::class]);
        $reversal = $this->refundService->reversePayment($original, 'nothing to reverse', $this->user->id);

        self::assertNull($reversal, 'already unwound; nothing to reverse');
        Event::assertNotDispatched(PaymentReversed::class);
        self::assertSame($paymentsBefore, Payment::query()->count());
        self::assertSame($allocationsBefore, PaymentAllocation::query()->count());
        self::assertSame(
            0,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
        );
    }

    /**
     * D-4 — a zero-net reversal is ALLOWED, not refused: the document-per-action
     * record must not depend on refund history. `partialRefund()` never flips the
     * original's status, so a payment fully refunded piecemeal is still
     * `Completed` and still reversible.
     */
    public function test_a_fully_piecemeal_refunded_payment_reverses_to_a_zero_amount_document(): void
    {
        $invoice = $this->paidInvoice('1000.00');
        $original = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        $this->refundService->partialRefund($original, '600.00', 'first', $this->user->id);
        $this->refundService->partialRefund($original, '400.00', 'second', $this->user->id);

        $original->refresh();
        self::assertSame(PaymentStatus::Completed, $original->status);

        $reversal = $this->refundService->reversePayment($original, 'zero-net reverse', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame('0.000', $reversal->amount);
        self::assertSame(
            0,
            PaymentAllocation::query()->where('payment_id', $reversal->id)->count(),
            'every document already nets to zero — no mirror row is needed',
        );
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->where('source_id', $reversal->id)
                ->count(),
            'a zero-amount reversal posts no journal entry',
        );
        $this->assertDatabaseCount('repository_movements', 0);

        $this->assertDocumentNetsToZero($invoice);
    }

    /**
     * D-17 case A: the original posted NO journal entry (a legacy admin payment
     * with no repository), so the reversal posts none either — document plus
     * mirrors only, no exception. This is the case that keeps
     * TreasuryEventDispatchTest / PrivilegedAuditLogDispatchTest green.
     */
    public function test_an_original_with_no_journal_entry_reverses_with_no_gl_and_no_movement(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');
        self::assertNull($original->journal_entry_id);
        self::assertNull($original->repository_id);

        $reversal = $this->refundService->reversePayment($original, 'legacy admin payment', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertNull($reversal->journal_entry_id);
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
        );
        $this->assertDatabaseCount('repository_movements', 0);
        $this->assertDocumentNetsToZero($invoice);
    }

    // ---------------------------------------------------------------------
    // T10 — the two reference-heuristic refund readers (plan D-11)
    // ---------------------------------------------------------------------

    /**
     * `getRefundHistory()` selects by `amount < 0 AND reference LIKE '%…%'` with NO
     * type filter, and V4's reversal row is a negative payment whose reference
     * contains the original's — so without the added type filter it would be
     * counted into `total_refunded` and a reversed-not-refunded payment would
     * report a refund that never happened.
     */
    public function test_the_refund_history_does_not_count_the_reversal_row(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');

        $this->refundService->reversePayment($original, 'reversed not refunded', $this->user->id);

        $history = $this->refundService->getRefundHistory($original->fresh() ?? $original);

        self::assertSame(0, bccomp('0', (string) $history['total_refunded'], 3), 'nothing was REFUNDED');
        self::assertSame(0, $history['refund_count']);
        self::assertFalse($history['is_fully_refunded']);
    }

    /** A real refund is still counted — the filter must not over-tighten. */
    public function test_the_refund_history_still_counts_real_refunds_after_a_reversal_exists(): void
    {
        $invoice = $this->paidInvoice('1000.00');
        $original = $this->paymentAllocatedTo([[$invoice, '1000.00']], '1000.00');

        $this->refundService->partialRefund($original, '400.00', 'partial', $this->user->id);
        $this->refundService->reversePayment($original->fresh() ?? $original, 'then reverse', $this->user->id);

        $history = $this->refundService->getRefundHistory($original->fresh() ?? $original);

        self::assertSame(
            0,
            bccomp('400', (string) $history['total_refunded'], 3),
            'the 400 refund counts; the 600 reversal does not',
        );
        self::assertSame(1, $history['refund_count']);
    }

    /**
     * `findExistingFullRefund()` uses the same untyped reference heuristic. Asked
     * for the full refund of a REVERSED-not-refunded payment it would otherwise
     * hand back the REVERSAL row. With the type filter it finds nothing and throws
     * its existing "data integrity issue" error, which is now ACCURATE.
     */
    public function test_refunding_a_reversed_payment_never_returns_the_reversal_row(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00');

        $reversal = $this->refundService->reversePayment($original, 'reverse first', $this->user->id);
        self::assertInstanceOf(Payment::class, $reversal);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no refund record found');

        $this->refundService->refundPayment($original->fresh() ?? $original, 'now refund', $this->user->id);
    }

    // ---------------------------------------------------------------------
    // T7 — the CASH branch
    // ---------------------------------------------------------------------

    /**
     * The cash branch, end to end: exactly one `customer_payment_refund` entry
     * whose `source_id` is the REVERSAL payment id (never the original — D-9),
     * `Dr AR / Cr repository gl_account` at the net, and exactly one movement OUT
     * whose idempotency key cannot collide with any refund key (D-8).
     */
    public function test_a_repository_backed_reversal_posts_one_ar_entry_and_one_movement_out(): void
    {
        $repository = $this->ledgeredRepository();
        $balanceBefore = (string) $repository->balance;
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'repository_id' => $repository->id,
            'journal_entry_id' => $this->postedPaymentEntry()->id,
        ]);

        $reversal = $this->refundService->reversePayment($original, 'cash reversal', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);

        $entries = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'customer_payment_refund')
            ->get();
        self::assertCount(1, $entries, 'exactly one reversing entry');
        self::assertSame($reversal->id, $entries->first()->source_id, 'D-9: source_id is the REVERSAL payment');

        $arAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $lines = JournalLine::query()->where('journal_entry_id', $entries->first()->id)->get()->keyBy('account_id');
        self::assertSame(0, bccomp('500.000', (string) $lines[$arAccount->id]->debit, 3), 'Dr AR at the net');
        self::assertSame(
            0,
            bccomp('500.000', (string) $lines[(string) $repository->gl_account_id]->credit, 3),
            'Cr the repository GL account at the net',
        );

        // Scoped to the REFUND-sourced leg: the fixture's opening balance is itself
        // a (port-recorded) movement on this repository.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repository->id)
            ->where('source_type', MovementSourceType::Refund->value)
            ->get();
        self::assertCount(1, $movements);
        self::assertSame('out', (string) $movements->first()->direction);
        self::assertSame(
            "refund:{$original->id}:reversal:{$reversal->id}",
            (string) $movements->first()->idempotency_key,
            'D-8: MovementSourceType::Refund is reused, distinguished by the leg',
        );
        self::assertNotNull($movements->first()->journal_entry_id);

        self::assertSame(
            0,
            bccomp('500.000', bcsub($balanceBefore, (string) ($repository->fresh()?->balance ?? '0'), 3), 3),
            'the repository balance drops by exactly the net',
        );

        $this->assertDocumentNetsToZero($invoice);
    }

    /**
     * D-17 case B — the original DID post a journal entry but has NO repository.
     * Reversing it via the cash branch would leave real, unreversed GL drift
     * standing, so it REFUSES. (Unreachable through the API today: the payment
     * writers only post an entry when a repository exists. This is the safety net
     * that makes D-17 a genuinely uniform rule rather than a one-sided one.)
     */
    public function test_an_original_with_a_journal_entry_but_no_repository_refuses(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'journal_entry_id' => $this->postedPaymentEntry()->id,
        ]);

        $this->assertReversalRefused($original, 'repository');
    }

    public function test_a_repository_without_a_gl_account_refuses(): void
    {
        $repository = $this->ledgeredRepository();
        $repository->forceFill(['gl_account_id' => null])->save();

        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'repository_id' => $repository->id,
            'journal_entry_id' => $this->postedPaymentEntry()->id,
        ]);

        $this->assertReversalRefused($original, 'gl_account_id');
    }

    /** N9: a repository whose currency diverges from the payment's refuses. */
    public function test_a_repository_currency_mismatch_refuses(): void
    {
        $repository = $this->ledgeredRepository('TND');

        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'repository_id' => $repository->id,
            'journal_entry_id' => $this->postedPaymentEntry()->id,
        ]);

        $this->assertReversalRefused($original, 'currency');
    }

    /**
     * OQ-3 mandated test (`ReversalSupport::NoCashLeg`). NOTE: `CreditApplication`
     * has ZERO writers repo-wide, so this fixture is hand-crafted — it is a
     * FORWARD GUARD for a future writer, not a behaviour test of a live path.
     */
    public function test_a_credit_application_reversal_leaves_the_customer_credit_balance_unchanged(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'payment_type' => PaymentType::CreditApplication,
        ]);

        $before = $this->partnerCreditBalance();

        $reversal = $this->refundService->reversePayment($original, 'credit application reversal', $this->user->id);

        self::assertInstanceOf(Payment::class, $reversal);
        self::assertSame($before, $this->partnerCreditBalance(), 'the customer credit balance must not move');
        $this->assertDatabaseCount('repository_movements', 0);
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
            'NoCashLeg posts no cash-side entry',
        );
        $this->assertDocumentNetsToZero($invoice);
    }

    /**
     * N3 — the assertion that makes D-17 uniform. `NoCashLeg` suppresses the cash
     * MOVEMENT only; the GL symmetry rule still applies. Since there is no correct
     * credit-side reversing shape, a `CreditApplication` that CARRIES a journal
     * entry must refuse rather than orphan it (the exact drift case B refuses for
     * a `DocumentPayment`).
     */
    public function test_a_credit_application_carrying_a_journal_entry_refuses(): void
    {
        $invoice = $this->paidInvoice('500.00');
        $original = $this->paymentAllocatedTo([[$invoice, '500.00']], '500.00', [
            'payment_type' => PaymentType::CreditApplication,
            'journal_entry_id' => $this->postedPaymentEntry()->id,
        ]);

        $this->assertReversalRefused($original, 'credit');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function assertReversalRefused(Payment $original, string $messageFragment): void
    {
        $paymentsBefore = Payment::query()->count();
        $allocationsBefore = PaymentAllocation::query()->count();
        // Relative, not absolute: a ledgered-repository fixture carries a
        // port-recorded opening-balance movement of its own.
        $movementsBefore = DB::table('repository_movements')->count();

        try {
            $this->refundService->reversePayment($original, 'must refuse', $this->user->id);
            self::fail('reversePayment() must refuse');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                $messageFragment,
                strtolower($exception->getMessage()),
                'refusal message must name the reason',
            );
        }

        self::assertSame($paymentsBefore, Payment::query()->count(), 'no payment row written');
        self::assertSame($allocationsBefore, PaymentAllocation::query()->count(), 'no allocation written');
        self::assertSame(PaymentStatus::Completed, $original->fresh()?->status, 'original stays Completed');
        self::assertSame(
            0,
            Payment::query()->where('payment_type', PaymentType::Reversal->value)->count(),
        );
        self::assertSame(
            $movementsBefore,
            DB::table('repository_movements')->count(),
            'no cash movement was recorded',
        );
        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)
                ->where('source_type', 'customer_payment_refund')
                ->count(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerCreditBalance(): array
    {
        $balance = app(MultiPaymentService::class)->getPartnerAccountBalance(
            $this->tenant->id,
            $this->company->id,
            $this->customer->id,
            'EUR',
        );
        unset($balance['deposits']);

        return $balance;
    }

    /**
     * A POSTED journal entry standing in for the original payment's own GL leg —
     * D-17 only asks whether the original posted one.
     */
    private function postedPaymentEntry(): JournalEntry
    {
        return JournalEntry::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.Str::random(8),
            'entry_date' => now(),
            'description' => 'Original customer payment',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'customer_payment',
            'journal_code' => JournalCode::fromSourceType('customer_payment')->value,
            'source_id' => Str::uuid()->toString(),
        ]);
    }

    /**
     * The invariant that REPLACES the old allocation wipe. The SUM assertion is
     * load-bearing: `balance_due` alone cannot detect rounding dust on SQLite.
     */
    private function assertDocumentNetsToZero(Document $document): void
    {
        $sum = PaymentAllocation::query()
            ->where('document_id', $document->id)
            ->get('amount')
            ->reduce(
                static fn (string $carry, PaymentAllocation $row): string => bcadd($carry, (string) $row->amount, 3),
                '0',
            );

        self::assertSame(
            0,
            bccomp($sum, '0', 3),
            "document {$document->document_number}: SUM(payment_allocations) must be exactly 0, got {$sum}",
        );

        $document->refresh();
        self::assertSame(
            0,
            bccomp((string) $document->balance_due, (string) $document->total, 3),
            "document {$document->document_number}: balance_due must equal total",
        );
        self::assertSame(DocumentStatus::Posted, $document->status);
    }

    private function paidInvoice(string $total): Document
    {
        return Document::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.Str::random(8),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Paid,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => '0.00',
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  list<array{0: Document, 1: string}>  $allocations
     * @param  array<string, mixed>  $overrides
     */
    private function paymentAllocatedTo(array $allocations, string $amount, array $overrides = []): Payment
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
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-'.Str::random(8),
            'created_by' => $this->user->id,
            ...$overrides,
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

    /**
     * `balance` and `currency` are PORT-MANAGED on `PaymentRepository` (Task 22)
     * and deliberately not fillable.
     *
     * `currency` is seeded with a direct column write (the `forbid_direct_balance_write`
     * trigger guards `balance` only). The opening BALANCE must go through
     * `TreasuryMovementService` — a Postgres trigger rejects any direct write to
     * that column, and it is a no-op on SQLite, so a direct update passes locally
     * and fails on the real database.
     */
    private function ledgeredRepository(string $currency = 'EUR'): PaymentRepository
    {
        $repository = PaymentRepository::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BNK-'.Str::random(4),
            'name' => 'Bank Account',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
        ]);

        DB::table('payment_repositories')
            ->where('id', $repository->id)
            ->update(['currency' => $currency]);

        $repository->refresh();

        DB::transaction(fn () => app(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $repository->tenant_id,
            companyId: $repository->company_id,
            direction: MovementDirection::In,
            amount: '1000.000',
            currency: $repository->currency,
            sourceType: MovementSourceType::OpeningBalance,
            sourceId: $repository->id,
            idempotencyLeg: 'opening',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: 'Test fixture opening balance',
            allowWhileFrozen: false,
        )));

        return $repository->fresh() ?? $repository;
    }
}
