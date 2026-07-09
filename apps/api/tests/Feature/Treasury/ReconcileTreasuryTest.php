<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Mockery\LegacyMockInterface;
use Tests\TestCase;

/**
 * Task 24 (Treasury Money-Movement Spine, Wave F): `treasury:reconcile` with
 * freeze-on-drift. This is the EMPIRICAL validation of the whole spine — a
 * cleanly-converged tenant must reconcile GREEN (no freeze); ANY drift freezes
 * the repository (never repairs) and raises an alert (log + audit_events).
 *
 * Reconciliation checks (spec §9.2):
 *   1. balance == Σ signed movements + balance_after/ordinal continuity.
 *   2. every non-exempt movement's journal_entry_id exists (Posted) w/ matching
 *      amount (exempt: opening_balance + same-GL-account transfer legs).
 *   3. every transfer_group_id nets to zero across its legs.
 */
final class ReconcileTreasuryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Rule 20: the reconcile command runs in console context with NO
        // CompanyContext — clear it so scale resolution is exercised via the
        // explicit repository currency, matching the scheduler reality.
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    private function seedRepository(?string $glAccountId = null): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
            'gl_account_id' => $glAccountId ?? $this->cashAccount->id,
            'next_movement_ordinal' => 0,
            'frozen_at' => null,
            'frozen_reason' => null,
        ]);
    }

    /**
     * A posted journal entry with a single debit line on the given account for
     * the given amount — the GL justification a cash-IN movement reconciles
     * against (spec §9.2 check 2).
     *
     * @param  numeric-string  $amount
     */
    private function postedCashEntry(string $accountId, string $amount): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-REC-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Reconcile fixture',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test_cash',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $accountId,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Cash in',
            'line_order' => 0,
        ]);

        return $entry;
    }

    /**
     * Record one legitimate cash-IN movement through the write port.
     *
     * @param  numeric-string  $amount
     */
    private function recordIn(
        PaymentRepository $repo,
        string $amount,
        MovementSourceType $sourceType = MovementSourceType::Payment,
        ?string $journalEntryId = null,
    ): string {
        $intent = new MovementIntent(
            repositoryId: $repo->id,
            tenantId: $repo->tenant_id,
            companyId: $repo->company_id,
            direction: MovementDirection::In,
            amount: $amount,
            currency: $repo->currency,
            sourceType: $sourceType,
            sourceId: (string) Str::uuid(),
            idempotencyLeg: 'main',
            journalEntryId: $journalEntryId,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: false,
        );

        return DB::transaction(fn () => $this->service()->record($intent))->movementId;
    }

    /**
     * Raw-insert a movement row, bypassing the port (the append-only trigger
     * guards UPDATE/DELETE/TRUNCATE only — INSERT is allowed). Used to seed
     * drift the port could never legitimately produce.
     *
     * @param  numeric-string  $amount
     * @param  numeric-string  $balanceAfter
     */
    private function rawMovement(
        PaymentRepository $repo,
        MovementDirection $direction,
        string $amount,
        string $balanceAfter,
        int $ordinal,
        MovementSourceType $sourceType,
        ?string $journalEntryId,
        ?string $transferGroupId = null,
    ): void {
        DB::table('repository_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $repo->tenant_id,
            'company_id' => $repo->company_id,
            'payment_repository_id' => $repo->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => $repo->currency,
            'balance_after' => $balanceAfter,
            'ordinal' => $ordinal,
            'source_type' => $sourceType->value,
            'source_id' => (string) Str::uuid(),
            'journal_entry_id' => $journalEntryId,
            'idempotency_key' => 'raw:'.Str::uuid(),
            'transfer_group_id' => $transferGroupId,
            'reverses_movement_id' => null,
            'reason_code' => null,
            'occurred_at' => CarbonImmutable::now(),
            'created_by' => null,
            'recorded_while_frozen' => false,
            'notes' => 'seeded drift',
        ]);
    }

    private function reconcile(): int
    {
        return Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]);
    }

    private function driftEventCount(string $repositoryId): int
    {
        return AuditEvent::query()
            ->where('event_type', 'treasury.reconcile.drift')
            ->where('aggregate_id', $repositoryId)
            ->count();
    }

    // ── (a) CLEAN tenant reconciles GREEN — the empirical spine proof ──────────

    public function test_clean_tenant_reconciles_green_and_does_not_freeze(): void
    {
        $repo = $this->seedRepository();

        $je1 = $this->postedCashEntry($this->cashAccount->id, '30.000');
        $this->recordIn($repo, '30.000', journalEntryId: $je1->id);
        $je2 = $this->postedCashEntry($this->cashAccount->id, '20.000');
        $this->recordIn($repo, '20.000', journalEntryId: $je2->id);

        $repo->refresh();
        self::assertSame('50.000', $repo->balance);

        $exit = $this->reconcile();

        self::assertSame(0, $exit);
        $repo->refresh();
        self::assertNull($repo->frozen_at, 'A cleanly-converged repository must NOT be frozen.');
        self::assertSame(0, $this->driftEventCount($repo->id));
    }

    // ── (b) balance / continuity drift → freeze + alert ────────────────────────

    public function test_balance_continuity_drift_freezes_and_alerts(): void
    {
        $repo = $this->seedRepository();
        $je = $this->postedCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($repo, '10.000', journalEntryId: $je->id);

        // Raw ordinal-2 leg whose balance_after breaks continuity (running sum
        // would be 15.000, not the 10.000 stored) — balance != Σ movements.
        $this->rawMovement(
            repo: $repo,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '10.000',
            ordinal: 2,
            sourceType: MovementSourceType::OpeningBalance,
            journalEntryId: null,
        );

        $logSpy = Log::spy();

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'Drift must surface a non-zero exit.');
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);
        self::assertNotNull($repo->frozen_reason);
        self::assertStringContainsStringIgnoringCase('balance', (string) $repo->frozen_reason);
        self::assertSame(1, $this->driftEventCount($repo->id));

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', ['treasury.reconcile.drift', Mockery::type('array')]);
    }

    // ── (c) null journal_entry_id on a non-exempt movement → freeze ────────────

    public function test_missing_journal_entry_on_non_exempt_movement_freezes(): void
    {
        $repo = $this->seedRepository();

        // A payment movement whose journal_entry_id failed to attach: balance
        // stays consistent (port-written), but check 2 has no GL justification.
        $this->recordIn($repo, '10.000', sourceType: MovementSourceType::Payment, journalEntryId: null);

        $exit = $this->reconcile();

        self::assertSame(1, $exit);
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);
        self::assertStringContainsStringIgnoringCase('journal', (string) $repo->frozen_reason);
        self::assertSame(1, $this->driftEventCount($repo->id));
    }

    public function test_opening_balance_movement_without_journal_entry_is_exempt(): void
    {
        $repo = $this->seedRepository();

        // opening_balance legs legitimately carry a null journal_entry_id.
        $this->recordIn($repo, '10.000', sourceType: MovementSourceType::OpeningBalance, journalEntryId: null);

        $exit = $this->reconcile();

        self::assertSame(0, $exit);
        $repo->refresh();
        self::assertNull($repo->frozen_at);
    }

    // ── (d) after a reconcile freeze, an interactive record() throws ───────────

    public function test_interactive_record_throws_after_reconcile_freeze(): void
    {
        $repo = $this->seedRepository();
        $this->recordIn($repo, '10.000', sourceType: MovementSourceType::Payment, journalEntryId: null);

        $this->reconcile();
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);

        $this->expectException(RepositoryFrozenException::class);

        $intent = new MovementIntent(
            repositoryId: $repo->id,
            tenantId: $repo->tenant_id,
            companyId: $repo->company_id,
            direction: MovementDirection::In,
            amount: '5.000',
            currency: $repo->currency,
            sourceType: MovementSourceType::Payment,
            sourceId: (string) Str::uuid(),
            idempotencyLeg: 'post-freeze',
            journalEntryId: null,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: false,
        );

        DB::transaction(fn () => $this->service()->record($intent));
    }

    // ── (e) transfer clearing: clean pair passes, broken group freezes ─────────

    public function test_clean_transfer_pair_does_not_freeze(): void
    {
        $repoA = $this->seedRepository();
        $repoB = $this->seedRepository();

        // Fund A (opening_balance leg is JE-exempt), then transfer A→B. Both
        // repositories share the same GL account, so the transfer legs carry a
        // null journal_entry_id legitimately (same-GL-account transfer).
        $this->recordIn($repoA, '20.000', sourceType: MovementSourceType::OpeningBalance, journalEntryId: null);
        $this->service()->transfer(new TransferIntent(
            fromRepositoryId: $repoA->id,
            toRepositoryId: $repoB->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: '10.000',
            currency: 'TND',
            transferGroupId: (string) Str::uuid(),
            journalEntryId: null,
            occurredAt: null,
            createdBy: null,
            notes: null,
        ));

        $exit = $this->reconcile();

        self::assertSame(0, $exit);
        $repoA->refresh();
        $repoB->refresh();
        self::assertNull($repoA->frozen_at);
        self::assertNull($repoB->frozen_at);
    }

    public function test_broken_transfer_group_freezes(): void
    {
        $repoA = $this->seedRepository();
        $repoB = $this->seedRepository();

        $this->recordIn($repoA, '20.000', sourceType: MovementSourceType::OpeningBalance, journalEntryId: null);
        $groupId = (string) Str::uuid();
        $this->service()->transfer(new TransferIntent(
            fromRepositoryId: $repoA->id,
            toRepositoryId: $repoB->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: '10.000',
            currency: 'TND',
            transferGroupId: $groupId,
            journalEntryId: null,
            occurredAt: null,
            createdBy: null,
            notes: null,
        ));

        // Plant a third, unbalanced leg into the SAME transfer group on a fresh
        // repository. A's own chain stays consistent (check 1 green), but the
        // group -10 (A out) +10 (B in) +5 (D in) nets to +5 ≠ 0 → check 3.
        $repoD = $this->seedRepository();
        $this->rawMovement(
            repo: $repoD,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '5.000',
            ordinal: 1,
            sourceType: MovementSourceType::Transfer,
            journalEntryId: null,
            transferGroupId: $groupId,
        );

        $exit = $this->reconcile();

        self::assertSame(1, $exit);
        $repoA->refresh();
        self::assertNotNull($repoA->frozen_at, 'A repo in an imbalanced transfer group must freeze.');
        self::assertStringContainsStringIgnoringCase('transfer', (string) $repoA->frozen_reason);
        self::assertSame(1, $this->driftEventCount($repoA->id));
    }
}
