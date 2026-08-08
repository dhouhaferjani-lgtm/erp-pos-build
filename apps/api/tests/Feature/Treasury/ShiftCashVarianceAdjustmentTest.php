<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DPA lane G3 — shift-close cash variance reaches the general ledger.
 *
 * Every test drives the mutation ALL THE WAY TO THE POSTED LEDGER: the
 * `repository_adjustments` document, the POSTED 658/758 journal entry, the
 * trial-balance sums on both accounts, the repository movement, and the
 * cross-links between the three.
 *
 * `CompanyContext` is CLEARED before every dispatch. Both trigger paths raise
 * `CashCountRecorded` from a `DB::afterCommit` callback and the offline one can
 * be replayed by a queue worker, so a listener that leaned on a bound company —
 * or on a bare no-arg `getScale()` — would work in the suite and throw in
 * production (CLAUDE.md rules 19 + 20).
 */
final class ShiftCashVarianceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Account $cashAccount;

    private PaymentRepository $till;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = $this->accountFor(SystemAccountPurpose::Cash);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->till = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '400.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->cashAccount->id,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $this->till->id,
        ]);

        // The lane ships DISABLED (gate finding I1). Every test that expects a
        // booking opts in explicitly; test_the_listener_ships_disabled below
        // asserts the default.
        config()->set('treasury.shift_variance_gl_enabled', true);
    }

    public function test_a_short_till_books_dr_658_cr_cash_with_document_entry_and_movement_cross_linked(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame('out', $document->direction->value);
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));
        $this->assertSame('count_variance', $document->reason_code->value);
        $this->assertSame($this->till->id, $document->payment_repository_id);
        $this->assertNotNull($document->journal_entry_id);
        $this->assertNotNull($document->movement_id);

        // The movement points back at the document AND carries the entry.
        $movement = DB::table('repository_movements')->where('id', $document->movement_id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('adjustment', $movement->source_type);
        $this->assertSame($document->id, $movement->source_id);
        $this->assertSame($document->journal_entry_id, $movement->journal_entry_id);
        $this->assertSame("adjustment:{$document->id}:shift:{$shiftId}", $movement->idempotency_key);
        $this->assertSame('out', $movement->direction);

        // Cash left the till.
        $this->assertSame('395.000', $this->till->fresh()?->balance);

        // POSTED, balanced, Dr 658 / Cr cash — asserted on the trial balance.
        $entry = JournalEntry::query()->whereKey($document->journal_entry_id)->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('repository_adjustment', $entry->source_type);
        $this->assertSame($document->id, $entry->source_id);

        $this->assertSame('5.000', $this->postedDebit($this->accountFor(SystemAccountPurpose::PaymentToleranceExpense)));
        $this->assertSame('0.000', $this->postedCredit($this->accountFor(SystemAccountPurpose::PaymentToleranceExpense)));
        $this->assertSame('5.000', $this->postedCredit($this->cashAccount));
    }

    public function test_an_over_till_books_dr_cash_cr_758(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '123.5000');

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame('in', $document->direction->value);
        $this->assertSame(0, bccomp((string) $document->amount, '3.500', 3));

        $this->assertSame('403.500', $this->till->fresh()?->balance);
        $this->assertSame('3.500', $this->postedCredit($this->accountFor(SystemAccountPurpose::PaymentToleranceIncome)));
        $this->assertSame('3.500', $this->postedDebit($this->cashAccount));
    }

    public function test_a_balanced_count_writes_no_document_no_entry_and_no_movement(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '120.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    public function test_a_variance_below_the_currency_smallest_unit_writes_nothing(): void
    {
        $shiftId = (string) Str::uuid();

        // TND is scale 3; a scale-4 variance of 0.0004 normalizes to 0.000.
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '120.0004');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
    }

    public function test_replaying_the_same_shift_count_writes_exactly_one_of_each_artifact(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());

        // The balance moved ONCE — the replay must not double-debit the till.
        $this->assertSame('395.000', $this->till->fresh()?->balance);
        $this->assertSame('5.000', $this->postedDebit($this->accountFor(SystemAccountPurpose::PaymentToleranceExpense)));
    }

    /**
     * The double-count guard, pinned.
     *
     * A per-receipt tolerance write-off (Dr 658 / Cr ProductRevenue) already
     * exists for this shift. Because `pos_receipt_payments.amount` stores the
     * TENDERED amount, that tolerance is already netted out of the expected-cash
     * basis — so a correct count is BALANCED and the shift close must NOT book a
     * second 658 leg. 658 must still carry exactly the per-receipt amount.
     */
    public function test_a_per_receipt_tolerance_is_not_re_booked_by_the_shift_variance(): void
    {
        $shiftId = (string) Str::uuid();
        $toleranceExpense = $this->accountFor(SystemAccountPurpose::PaymentToleranceExpense);

        // The per-receipt tolerance the POS already posted during the shift.
        app(GeneralLedgerService::class)
            ->postEntry(
                DB::transaction(fn (): JournalEntry => app(GeneralLedgerService::class)
                    ->createPOSPaymentToleranceEntry(
                        companyId: $this->company->id,
                        receiptId: (string) Str::uuid(),
                        amount: '0.150',
                        date: now(),
                    )),
                $this->cashier,
            );

        $this->assertSame('0.150', $this->postedDebit($toleranceExpense));

        // Expected cash ALREADY nets the tolerance (it is the tendered cash), so
        // an honest count is balanced.
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '120.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(
            '0.150',
            $this->postedDebit($toleranceExpense),
            '658 must carry the per-receipt tolerance ONLY — the shift close must not re-book it.',
        );
    }

    /**
     * Gate finding I1 — the lane SHIPS DISABLED, pending the owner ruling on POS
     * count semantics. Nothing runs, not even an audit row.
     */
    public function test_the_listener_ships_disabled_and_writes_nothing(): void
    {
        config()->set('treasury.shift_variance_gl_enabled', false);

        $shiftId = (string) Str::uuid();
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame(0, DB::table('audit_events')->where('aggregate_id', $shiftId)->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    public function test_the_default_configuration_is_disabled(): void
    {
        // Read the config FILE, not the runtime value setUp() opts in to — the
        // point is that a deployment which sets no env var gets the lane OFF.
        /** @var array<string, mixed> $treasuryConfig */
        $treasuryConfig = require base_path('config/treasury.php');

        $this->assertFalse(
            $treasuryConfig['shift_variance_gl_enabled'],
            'config/treasury.php must default the shift-variance GL leg to OFF (gate finding I1).',
        );
    }

    /**
     * Gate finding C1/I2 — the booked amount is the event aggregate (the figure
     * `pos_shifts.variance` carries and the fraud alert tests), never a
     * re-derivation from the per-tender rows.
     */
    public function test_the_booked_amount_is_the_event_aggregate(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));
    }

    /**
     * Gate finding C1/I2 — when the declared aggregate and the per-tender sum
     * disagree, the attribution set does not describe the amount, so nothing is
     * booked and the omission is durable.
     */
    public function test_an_aggregate_that_disagrees_with_the_breakdown_is_refused_and_audited(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchWithAggregate(
            $shiftId,
            [$this->breakdown($this->cashMethod->id, '120.0000', '115.0000')],
            aggregate: '-9.0000',
        );

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertRefusalAudited($shiftId, 'aggregate_breakdown_mismatch');
    }

    /**
     * Gate finding I3 — the offline sync endpoint validates only `uuid|distinct`
     * on `payment_method_id`, so a CARD tender's "variance" can reach the
     * listener. The live path rejects it (`method_not_physical`); the offline one
     * now cannot slip past either.
     */
    public function test_a_non_physical_tender_is_refused_and_audited(): void
    {
        $cardMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Card',
            'is_physical' => false,
            'is_active' => true,
            'default_repository_id' => $this->till->id,
        ]);

        $shiftId = (string) Str::uuid();
        $this->dispatch($shiftId, [$this->breakdown($cardMethod->id, '120.0000', '115.0000')]);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertRefusalAudited($shiftId, 'tender_not_physical_or_unknown');
    }

    /**
     * Gate finding I6 — a `payment_method_id` that does not load in scope must
     * refuse, NOT fall back to whichever GL-linked repository sorts first.
     */
    public function test_an_unknown_payment_method_id_is_refused_rather_than_falling_back(): void
    {
        $shiftId = (string) Str::uuid();
        $this->dispatch($shiftId, [$this->breakdown((string) Str::uuid(), '120.0000', '115.0000')]);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
        $this->assertRefusalAudited($shiftId, 'tender_not_physical_or_unknown');
    }

    /**
     * Gate finding I7 — a physical cash count belongs in a cash till. The shared
     * fallback filters on `gl_account_id IS NOT NULL` only, so without this
     * caller-side assertion a tenant with no `default_repository_id` mapping
     * could have its cash variance booked against a BANK GL account.
     */
    public function test_a_resolved_bank_repository_is_refused_and_audited(): void
    {
        // No mapping anywhere, and the only GL-linked repository is a bank.
        PaymentMethod::query()->whereKey($this->cashMethod->id)->update(['default_repository_id' => null]);
        $this->till->forceFill(['gl_account_id' => null])->save();

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '0.000',
            'currency' => 'TND',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $this->accountFor(SystemAccountPurpose::Bank)->id,
            'is_active' => true,
        ]);

        $shiftId = (string) Str::uuid();
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertRefusalAudited($shiftId, 'resolved_repository_is_not_a_cash_till');
    }

    /**
     * Gate finding I4 — a booking is also durably recorded, and gate finding M1
     * — the scale-4 → scale-3 truncation residual is carried on that record
     * instead of vanishing.
     */
    public function test_a_booking_is_audited_and_carries_the_truncated_residual(): void
    {
        $shiftId = (string) Str::uuid();

        // TND is scale 3; a scale-4 variance of −5.0009 books 5.000 and leaves
        // a 0.0009 residual that must not silently disappear.
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '114.9991');

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));

        $audit = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_booked')
            ->where('aggregate_id', $shiftId)
            ->first();
        $this->assertNotNull($audit);
        $payload = (string) $audit->payload;
        $this->assertStringContainsString('truncated_residual', $payload);
        $this->assertStringContainsString('0.0009', $payload);
    }

    /**
     * Gate re-review N4 — the disposition of the single riskiest input, which
     * had no test at all: a shortfall larger than the till's cached balance.
     *
     * `allowNegative` stays false (parity with the manual adjustment endpoint),
     * so the movement port refuses and NOTHING is booked. That is a deliberate
     * policy outcome pending the owner ruling, not a fault — so it is audited
     * under its OWN machine-readable reason rather than the generic `exception`
     * bucket, which is what made it indistinguishable from a crash.
     */
    public function test_a_shortfall_larger_than_the_till_balance_is_refused_under_its_own_reason(): void
    {
        // A till already swept by a close-of-day deposit.
        $this->till->forceFill(['balance' => '1.000', 'allow_negative' => false])->save();

        $shiftId = (string) Str::uuid();
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('1.000', $this->till->fresh()?->balance);

        $this->assertRefusalAudited($shiftId, 'insufficient_repository_balance');

        // Specifically NOT the generic crash bucket.
        $audit = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->where('aggregate_id', $shiftId)
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('"reason":"exception"', (string) $audit->payload);
    }

    /**
     * The frozen-till companion: also a policy refusal (`allowWhileFrozen` is
     * false because the amount is server-computed, never an offline device
     * replay), also its own reason.
     */
    public function test_a_frozen_till_is_refused_under_its_own_reason(): void
    {
        $this->till->forceFill([
            'frozen_at' => now(),
            'frozen_reason' => 'Under reconciliation.',
        ])->save();

        $shiftId = (string) Str::uuid();
        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertRefusalAudited($shiftId, 'repository_frozen');
    }

    public function test_an_ambiguous_count_is_audited(): void
    {
        $otherRepository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '0.000',
            'currency' => 'TND',
            'type' => RepositoryType::Safe,
            'gl_account_id' => $this->accountFor(SystemAccountPurpose::Bank)->id,
            'is_active' => true,
        ]);

        $chequeMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHEQUE2',
            'name' => 'Cheque',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $otherRepository->id,
        ]);

        $shiftId = (string) Str::uuid();
        $this->dispatch($shiftId, [
            $this->breakdown($this->cashMethod->id, '120.0000', '115.0000'),
            $this->breakdown($chequeMethod->id, '80.0000', '82.0000'),
        ]);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertRefusalAudited($shiftId, 'ambiguous_repositories');
    }

    /**
     * The database-level backstop, not just the application one: even a caller
     * that mints its own document id cannot land a SECOND shift-variance
     * document on a shift that already has one
     * (2026_08_08_140000_unique_repository_adjustments_pos_shift.php).
     */
    public function test_the_database_refuses_a_second_document_for_the_same_shift(): void
    {
        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $existing = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('repository_adjustments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $existing->payment_repository_id,
            'direction' => 'out',
            'amount' => '1.000',
            'currency' => 'TND',
            'reason_code' => 'count_variance',
            'reason_text' => 'A second document for the same shift.',
            'pos_shift_id' => $shiftId,
            'created_by' => $this->cashier->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_no_resolvable_repository_blocks_nothing_and_writes_nothing(): void
    {
        // Strip the GL link from the only repository so nothing resolves.
        $this->till->forceFill(['gl_account_id' => null])->save();
        PaymentMethod::query()->whereKey($this->cashMethod->id)->update(['default_repository_id' => null]);

        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
    }

    public function test_tenders_resolving_to_two_repositories_are_refused_without_partial_writes(): void
    {
        $otherAccount = $this->accountFor(SystemAccountPurpose::Bank);
        $otherRepository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '0.000',
            'currency' => 'TND',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $otherAccount->id,
            'is_active' => true,
        ]);

        $chequeMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHEQUE',
            'name' => 'Cheque',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $otherRepository->id,
        ]);

        $shiftId = (string) Str::uuid();

        $this->dispatch($shiftId, [
            $this->breakdown($this->cashMethod->id, '120.0000', '115.0000'),
            $this->breakdown($chequeMethod->id, '80.0000', '82.0000'),
        ]);

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    public function test_a_balanced_second_tender_does_not_make_the_count_ambiguous(): void
    {
        $otherAccount = $this->accountFor(SystemAccountPurpose::Bank);
        $otherRepository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '0.000',
            'currency' => 'TND',
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $otherAccount->id,
            'is_active' => true,
        ]);

        $chequeMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHEQUE',
            'name' => 'Cheque',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $otherRepository->id,
        ]);

        $shiftId = (string) Str::uuid();

        $this->dispatch($shiftId, [
            $this->breakdown($this->cashMethod->id, '120.0000', '115.0000'),
            $this->breakdown($chequeMethod->id, '80.0000', '80.0000'),
        ]);

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame($this->till->id, $document->payment_repository_id);
        $this->assertSame('395.000', $this->till->fresh()?->balance);
        $this->assertSame('0.000', $otherRepository->fresh()?->balance);
    }

    public function test_a_chart_without_the_tolerance_purpose_blocks_nothing_and_writes_nothing(): void
    {
        Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::PaymentToleranceExpense->value)
            ->update(['system_purpose' => null]);

        $shiftId = (string) Str::uuid();

        $this->dispatchCount($shiftId, expected: '120.0000', actual: '115.0000');

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function dispatchCount(string $shiftId, string $expected, string $actual): void
    {
        $this->dispatch($shiftId, [$this->breakdown($this->cashMethod->id, $expected, $actual)]);
    }

    /**
     * @param  list<CashCountBreakdownDTO>  $breakdowns
     */
    private function dispatch(string $shiftId, array $breakdowns): void
    {
        $aggregate = '0.0000';
        foreach ($breakdowns as $b) {
            $aggregate = bcadd($aggregate, $b->varianceAmount, 4);
        }

        $this->dispatchWithAggregate($shiftId, $breakdowns, $aggregate);
    }

    /**
     * Dispatch with an aggregate that may deliberately disagree with the
     * breakdown — the shape gate finding C1/I2 is about.
     *
     * @param  list<CashCountBreakdownDTO>  $breakdowns
     */
    private function dispatchWithAggregate(string $shiftId, array $breakdowns, string $aggregate): void
    {

        // Queued/afterCommit reality: no company bound (CLAUDE.md rule 20).
        app(CompanyContext::class)->clear();

        event(new CashCountRecorded(
            zReportId: (string) Str::uuid(),
            shiftId: $shiftId,
            terminalId: (string) Str::uuid(),
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            cashierId: $this->cashier->id,
            managerOverrideBy: null,
            blindCountUsed: false,
            currencyCode: 'TND',
            aggregateVariance: new VarianceAmount(amount: $aggregate, currencyCode: 'TND'),
            varianceDirection: VarianceDirection::fromSignedAmount($aggregate),
            severity: VarianceSeverity::Warning,
            tenderBreakdown: $breakdowns,
            descriptionCode: 'pos.cash_count.warning',
            descriptionParams: [],
            recordedAt: now()->toIso8601String(),
        ));
    }

    private function assertRefusalAudited(string $shiftId, string $reason): void
    {
        $audit = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->where('aggregate_id', $shiftId)
            ->first();

        $this->assertNotNull($audit, "Expected a durable refusal audit event for shift {$shiftId}.");
        $this->assertStringContainsString($reason, (string) $audit->payload);
    }

    private function breakdown(string $methodId, string $expected, string $actual): CashCountBreakdownDTO
    {
        $variance = bcsub($actual, $expected, 4);

        return new CashCountBreakdownDTO(
            paymentMethodId: $methodId,
            currencyCode: 'TND',
            expectedAmount: $expected,
            actualAmount: $actual,
            varianceAmount: $variance,
            varianceDirection: VarianceDirection::fromSignedAmount($variance),
            transactionCount: 1,
        );
    }

    private function accountFor(SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }

    private function postedDebit(Account $account): string
    {
        return $this->postedSide($account, 'debit');
    }

    private function postedCredit(Account $account): string
    {
        return $this->postedSide($account, 'credit');
    }

    private function postedSide(Account $account, string $side): string
    {
        $total = '0.000';

        $lines = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('company_id', $this->company->id)
                ->where('status', JournalEntryStatus::Posted->value))
            ->get();

        foreach ($lines as $line) {
            $total = bcadd($total, (string) $line->{$side}, 3);
        }

        return $total;
    }
}
