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
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Mockery\LegacyMockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
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

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenant->id);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('treasury.manage', 'sanctum');
    }

    protected function tearDown(): void
    {
        // Safety net for test_repository_check_error_does_not_abort_later_repositories_and_exits_failure(),
        // which pins Carbon::setTestNow() to order two repositories' created_at —
        // guarantee it never leaks into a later test if an assertion fails first.
        Carbon::setTestNow();

        parent::tearDown();
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
        $validStatement = $this->statement($repo, BankStatementStatus::Reconciled, now(), '50.000', '60.000');
        BankStatementLine::query()->create([
            'bank_statement_id' => $validStatement->id,
            'payment_repository_id' => $repo->id,
            'line_number' => 1,
            'value_date' => '2026-06-30',
            'direction' => MovementDirection::In,
            'amount' => '10.000',
            'label' => 'Acknowledged statement discrepancy',
            'match_status' => StatementLineMatchStatus::Ignored,
            'ignore_reason' => StatementLineIgnoreReason::Informational,
            'ignore_text' => 'No treasury movement exists',
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);

        $exit = $this->reconcile();

        self::assertSame(0, $exit);
        $repo->refresh();
        self::assertNull($repo->frozen_at, 'A cleanly-converged repository must NOT be frozen.');
        self::assertSame(0, $this->driftEventCount($repo->id));
        self::assertDatabaseMissing('audit_events', [
            'event_type' => 'treasury.reconcile.statement_tamper',
            'aggregate_id' => $validStatement->id,
        ]);
    }

    public function test_tampered_reconciled_statement_alerts_without_freezing_repository(): void
    {
        $repo = $this->seedRepository();
        $statement = $this->statement($repo, BankStatementStatus::Reconciled, now(), '0.000', '1.000');

        $exit = $this->reconcile();

        self::assertSame(1, $exit);
        self::assertNull($repo->fresh()?->frozen_at);
        self::assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_type' => 'treasury.reconcile.statement_tamper',
            'aggregate_type' => 'BankStatement',
            'aggregate_id' => $statement->id,
        ]);
    }

    public function test_statement_stale_after_configured_days_alerts_but_recent_statement_is_clean(): void
    {
        config()->set('treasury.statement_stale_days', 7);
        $repo = $this->seedRepository();
        $stale = $this->statement($repo, BankStatementStatus::Imported, now()->subDays(8));
        $recent = $this->statement($repo, BankStatementStatus::Reconciling, now()->subDays(6));
        $voided = $this->statement($repo, BankStatementStatus::Voided, now()->subDays(60));

        $exit = $this->reconcile();

        self::assertSame(1, $exit);
        self::assertNull($repo->fresh()?->frozen_at);
        self::assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'event_type' => 'treasury.reconcile.statement_stale',
            'aggregate_type' => 'BankStatement',
            'aggregate_id' => $stale->id,
        ]);
        self::assertDatabaseMissing('audit_events', [
            'event_type' => 'treasury.reconcile.statement_stale',
            'aggregate_id' => $recent->id,
        ]);
        self::assertDatabaseMissing('audit_events', [
            'event_type' => 'treasury.reconcile.statement_stale',
            'aggregate_id' => $voided->id,
        ]);
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

    public function test_drift_freeze_notifies_only_managers_of_the_repository_company(): void
    {
        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $manager = $this->createTreasuryManager($this->company);
        $otherCompanyManager = $this->createTreasuryManager($otherCompany);
        $repo = $this->seedRepository();
        $je = $this->postedCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($repo, '10.000', journalEntryId: $je->id);
        $this->rawMovement(
            repo: $repo,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '10.000',
            ordinal: 2,
            sourceType: MovementSourceType::OpeningBalance,
            journalEntryId: null,
        );

        self::assertSame(1, $this->reconcile());

        $notification = DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', 'treasury.reconcile.drift')
            ->sole();
        $data = json_decode((string) $notification->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->company->id, $data['company_id']);
        self::assertSame("/treasury/repositories/{$repo->id}", $data['deep_link']);
        self::assertSame(0, DB::table('notifications')
            ->where('notifiable_id', $otherCompanyManager->id)
            ->where('type', 'treasury.reconcile.drift')
            ->count());
    }

    public function test_notification_send_failure_never_suppresses_freeze_or_audit(): void
    {
        $this->createTreasuryManager($this->company);
        $repo = $this->seedRepository();
        $je = $this->postedCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($repo, '10.000', journalEntryId: $je->id);
        $this->rawMovement(
            repo: $repo,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '10.000',
            ordinal: 2,
            sourceType: MovementSourceType::OpeningBalance,
            journalEntryId: null,
        );

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('simulated notification send failure'));
        $logSpy = Log::spy();

        self::assertSame(1, $this->reconcile());
        self::assertNotNull($repo->refresh()->frozen_at);
        self::assertSame(1, $this->driftEventCount($repo->id));
        $logSpy->shouldHaveReceived('error', [
            'treasury.reconcile.alert_failed',
            Mockery::on(static fn (array $context): bool => ($context['repository_id'] ?? null) === $repo->id
                && ($context['channel'] ?? null) === 'notification'),
        ]);
    }

    public function test_portfolio_drift_notification_and_failure_are_isolated_from_audit(): void
    {
        $manager = $this->createTreasuryManager($this->company);
        $portfolioAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5312',
        ]);
        $this->postedCashEntry($portfolioAccount->id, '5.000');

        self::assertSame(1, $this->reconcile());
        $notification = DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', 'treasury.reconcile.portfolio_drift')
            ->sole();
        $data = json_decode((string) $notification->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/finance/overview', $data['deep_link']);

        DB::table('notifications')->delete();
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('simulated portfolio notification failure'));
        $logSpy = Log::spy();

        self::assertSame(1, $this->reconcile());
        self::assertSame(2, AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'treasury.reconcile.portfolio_drift')
            ->count());
        $logSpy->shouldHaveReceived('error', [
            'treasury.reconcile.alert_failed',
            Mockery::on(fn (array $context): bool => ($context['company_id'] ?? null) === $this->company->id
                && ($context['channel'] ?? null) === 'notification'
                && ! array_key_exists('repository_id', $context)),
        ]);
    }

    // ── (b2) post-freeze alerting failure must still count as a freeze ─────────
    //
    // 2026-07-09 audit-fix-1 Rev-2 CRITICAL: freezeAndAlert() writes the durable
    // freeze UPDATE FIRST, then logs the drift line and writes the audit_events
    // row. If either of THOSE post-freeze steps throws, the repository is
    // already frozen in the DB — that must NEVER be misreported as a mere
    // per-repository ERROR (undercounting $frozen, losing the exit-code
    // truthfulness, and — because the outer catch's `treasury.reconcile.error`
    // log line would ALSO fire for an already-frozen repo — duplicating the
    // alert under a second, contradictory log shape for the same incident).

    public function test_post_freeze_alerting_failure_still_counts_as_frozen_not_errored(): void
    {
        $repo = $this->seedRepository();
        $je = $this->postedCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($repo, '10.000', journalEntryId: $je->id);

        // Same balance-continuity drift fixture as
        // test_balance_continuity_drift_freezes_and_alerts() — a genuine drift
        // finding that reaches freezeAndAlert().
        $this->rawMovement(
            repo: $repo,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '10.000',
            ordinal: 2,
            sourceType: MovementSourceType::OpeningBalance,
            journalEntryId: null,
        );

        // Force the post-freeze DRIFT alert log line to throw — simulating a
        // failure that happens strictly AFTER movementService->freeze() (the
        // freeze write precedes this log call in freezeAndAlert() and is not
        // wrapped by this mock). The alerting-failure fallback must then log
        // its own event, and NOT let the throw escape to the outer per-
        // repository catch (which would misclassify this as `$errored`).
        Log::shouldReceive('error')
            ->once()
            ->with('treasury.reconcile.drift', Mockery::type('array'))
            ->andThrow(new \RuntimeException('simulated audit/log failure'));

        Log::shouldReceive('info')->zeroOrMoreTimes();

        Log::shouldReceive('error')
            ->once()
            ->with('treasury.reconcile.alert_failed', Mockery::on(
                static fn (array $context): bool => ($context['repository_id'] ?? null) !== null
                    && ($context['tenant_id'] ?? null) !== null
                    && ($context['channel'] ?? null) === 'drift_log'
                    && array_key_exists('exception_message', $context),
            ))
            ->andReturnNull();

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'A genuine drift finding must still exit FAILURE even when post-freeze alerting fails.');

        $repo->refresh();
        self::assertNotNull($repo->frozen_at, 'The repository must end up frozen — the freeze UPDATE precedes the failing alert step.');
        self::assertNotNull($repo->frozen_reason);

        // 2026-07-10 audit follow-up: the drift log line and the audit_events
        // write now run in INDEPENDENT try/catch blocks (see
        // freezeAndAlert()), so a failure in the log channel alone must NOT
        // suppress the audit_event channel — the durable audit row IS
        // written even though the log line above threw. This is the
        // regression the shared-try shape had: before the split, the throw
        // aborted the try block before auditService->record() ever ran,
        // silently skipping a DB write that would otherwise have succeeded.
        self::assertSame(
            1,
            $this->driftEventCount($repo->id),
            'The audit_events row must still be written via its own independent try/catch even when the drift log line channel throws.',
        );

        $output = Artisan::output();
        self::assertStringContainsString(
            'froze 1',
            $output,
            'The command output must count this repository in the frozen total, not the error total.',
        );
        self::assertStringContainsString('0 error', $output);
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

    // ── (e2) authoritative cash-line amount match (Task-24 fix B) ──────────────
    //
    // The repository's OWN cash/bank GL line is authoritative: a movement whose
    // linked JE mis-books that line (wrong amount, or wrong side) is a real
    // cash/GL divergence and must FREEZE — it must NOT be rescued because some
    // OTHER line in the entry happens to carry the movement amount.

    public function test_misbooked_own_cash_line_freezes_even_when_another_line_carries_the_amount(): void
    {
        $repo = $this->seedRepository(); // gl_account_id = cashAccount

        // A liability line that DOES carry the movement amount (the false-negative
        // bait: the pre-fix global fallback matched this and let the drift escape).
        $ar = Account::factory()->liability()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Cash-IN of 100, but the drawer's own GL line is booked for only 80
        // (under-booked by 20) while AR carries 100. This is drift.
        $entry = $this->postedEntryWithLines([
            ['account_id' => $this->cashAccount->id, 'debit' => '80.000', 'credit' => '0'],
            ['account_id' => $ar->id, 'debit' => '0', 'credit' => '100.000'],
        ]);
        $this->recordIn($repo, '100.000', journalEntryId: $entry->id);

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'A movement whose own cash line is under-booked must FREEZE.');
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);
        self::assertStringContainsStringIgnoringCase('amount', (string) $repo->frozen_reason);
        self::assertSame(1, $this->driftEventCount($repo->id));
    }

    public function test_wrong_side_own_cash_line_freezes(): void
    {
        $repo = $this->seedRepository();

        // Cash-IN of 40 but the drawer's own GL line is CREDITED (a cash-IN must
        // DEBIT the cash account) — wrong side is drift.
        $rev = Account::factory()->liability()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $entry = $this->postedEntryWithLines([
            ['account_id' => $this->cashAccount->id, 'debit' => '0', 'credit' => '40.000'],
            ['account_id' => $rev->id, 'debit' => '40.000', 'credit' => '0'],
        ]);
        $this->recordIn($repo, '40.000', journalEntryId: $entry->id);

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'A cash-IN booked on the CREDIT side of the drawer must FREEZE.');
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);
    }

    public function test_bundled_entry_without_own_cash_line_does_not_false_freeze(): void
    {
        $repo = $this->seedRepository();

        // A bundled/reversal entry whose cash side lives on ANOTHER account (the
        // JE books NO line on the repo's own gl_account_id). The tolerant fallback
        // must keep this GREEN — a false freeze here would brick a live drawer.
        $otherCash = Account::factory()->asset()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $revenue = Account::factory()->liability()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $entry = $this->postedEntryWithLines([
            ['account_id' => $otherCash->id, 'debit' => '50.000', 'credit' => '0'],
            ['account_id' => $revenue->id, 'debit' => '0', 'credit' => '50.000'],
        ]);
        $this->recordIn($repo, '50.000', journalEntryId: $entry->id);

        $exit = $this->reconcile();

        self::assertSame(0, $exit, 'A bundled JE with no line on the repo cash account must NOT false-freeze.');
        $repo->refresh();
        self::assertNull($repo->frozen_at);
        self::assertSame(0, $this->driftEventCount($repo->id));
    }

    // ── (e3) scale tolerance (Task-24 fix B, MINOR) ────────────────────────────

    public function test_one_millime_scale_mismatch_is_tolerated_and_does_not_false_freeze(): void
    {
        // Simulates a tenant DB that missed the scale-3 widen migration: the JE
        // cash line truncated the millime (100.000) while the scale-3 movement
        // carries it (100.001). A strict compare would false-freeze every such
        // TND drawer; a 1-ULP tolerance keeps it GREEN.
        $repo = $this->seedRepository();
        $entry = $this->postedCashEntry($this->cashAccount->id, '100.000');
        $this->recordIn($repo, '100.001', journalEntryId: $entry->id);

        $exit = $this->reconcile();

        self::assertSame(0, $exit, 'A 1-millime scale mismatch must be tolerated, not frozen.');
        $repo->refresh();
        self::assertNull($repo->frozen_at);
    }

    // ── (e4) Draft-JE guard (Task-24 fix B, Fix 2 — synthetic corruption) ──────
    //
    // No converged flow records a movement against a Draft JE (every spine writer
    // posts synchronously before recording; the account-charge Draft-orphan has
    // no cash leg and records no movement). This proves the defensive Posted
    // check DOES freeze a Draft-linked movement should corruption ever produce one.

    public function test_draft_linked_movement_freezes(): void
    {
        $repo = $this->seedRepository();

        // Link the movement to a DRAFT JE through the port (which does not
        // validate JE status), so check 1 stays green on both drivers and check 2
        // is what freezes. The port write also satisfies the pgsql balance-write
        // trigger (a direct balance UPDATE is forbidden by forbid_direct_balance_write).
        $draft = $this->draftCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($repo, '10.000', sourceType: MovementSourceType::Payment, journalEntryId: $draft->id);

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'A movement linked to a Draft (unposted) JE must FREEZE.');
        $repo->refresh();
        self::assertNotNull($repo->frozen_at);
        self::assertStringContainsStringIgnoringCase('posted', (string) $repo->frozen_reason);
        self::assertSame(1, $this->driftEventCount($repo->id));
    }

    /**
     * A posted journal entry with the given lines. Reconcile does not validate JE
     * balance, so this seeds arbitrary (including deliberately misbooked) lines.
     *
     * @param  list<array{account_id: string, debit: numeric-string, credit: numeric-string}>  $lines
     */
    private function postedEntryWithLines(array $lines): JournalEntry
    {
        return $this->entryWithLines($lines, JournalEntryStatus::Posted);
    }

    /**
     * A DRAFT (unposted) journal entry with a single cash-debit line.
     *
     * @param  numeric-string  $amount
     */
    private function draftCashEntry(string $accountId, string $amount): JournalEntry
    {
        return $this->entryWithLines(
            [['account_id' => $accountId, 'debit' => $amount, 'credit' => '0']],
            JournalEntryStatus::Draft,
        );
    }

    /**
     * @param  list<array{account_id: string, debit: numeric-string, credit: numeric-string}>  $lines
     */
    private function entryWithLines(array $lines, JournalEntryStatus $status): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-REC-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Reconcile fixture',
            'status' => $status,
            'source_type' => 'test_cash',
            'source_id' => (string) Str::uuid(),
            'posted_at' => $status === JournalEntryStatus::Posted ? now() : null,
        ]);

        foreach ($lines as $order => $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => 'fixture line',
                'line_order' => $order,
            ]);
        }

        return $entry;
    }

    // ── (g) per-repository/per-tenant failure isolation (2026-07-09 audit N1) ──
    //
    // A single repository's check throwing (corrupt row / resolver failure /
    // audit-write failure / lock timeout) must NOT abort reconciliation for the
    // rest of the run: it must be caught, logged, counted as an error (never as
    // a freeze), and the run must continue to check every other repository —
    // in this same tenant and in any tenant that follows.

    public function test_repository_check_error_does_not_abort_later_repositories_and_exits_failure(): void
    {
        // $erroring is created strictly BEFORE $drifted (repositories are
        // checked in created_at order) and carries a currency wired — via the
        // decorator bound below — to make the very first line of detectDrift()
        // (scale resolution) throw. Pre-fix, this uncaught throw would abort the
        // bare foreach and $drifted would never be reached.
        Carbon::setTestNow(Carbon::parse('2026-07-09 08:00:00'));
        $erroring = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'ZZZ',
            'gl_account_id' => $this->cashAccount->id,
            'next_movement_ordinal' => 0,
            'frozen_at' => null,
            'frozen_reason' => null,
        ]);

        $this->app->instance(
            CurrencyScaleResolverInterface::class,
            new ThrowingScaleResolverDecorator(
                $this->app->make(CurrencyScaleResolverInterface::class),
                'ZZZ',
            ),
        );

        Carbon::setTestNow(Carbon::parse('2026-07-09 08:00:01'));
        $drifted = $this->seedRepository();
        $je = $this->postedCashEntry($this->cashAccount->id, '10.000');
        $this->recordIn($drifted, '10.000', journalEntryId: $je->id);
        // Raw ordinal-2 leg that breaks balance continuity — genuine drift.
        $this->rawMovement(
            repo: $drifted,
            direction: MovementDirection::In,
            amount: '5.000',
            balanceAfter: '10.000',
            ordinal: 2,
            sourceType: MovementSourceType::OpeningBalance,
            journalEntryId: null,
        );
        Carbon::setTestNow();

        $logSpy = Log::spy();

        $exit = $this->reconcile();

        self::assertSame(1, $exit, 'A per-repository check error must surface a non-zero (FAILURE) exit.');

        $erroring->refresh();
        self::assertNull(
            $erroring->frozen_at,
            'An errored check is a check FAILURE, not a drift FINDING — it must NOT freeze the repository.',
        );
        self::assertSame(0, $this->driftEventCount($erroring->id));

        $drifted->refresh();
        self::assertNotNull(
            $drifted->frozen_at,
            'A genuinely drifted repository created AFTER the errored one must still be checked and frozen — proof the throw did not abort the run.',
        );
        self::assertSame(1, $this->driftEventCount($drifted->id));

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', ['treasury.reconcile.error', Mockery::type('array')]);
    }

    // ── (f) REAL-BRIDGE green reconcile — the dominant FiscalEvent source type ──
    //
    // MAJOR 3: the clean cases above build movements from a hand-made Payment +
    // explicit JE. They never drive a real bridge, so the dominant real
    // `FiscalEvent` source type — and the null-JE deposit/account paths the
    // reconcile freezes on — were unproven. These two tests drive the ACTUAL
    // bridges end-to-end (Payment + allocation + GL + movement) and THEN reconcile.

    public function test_real_deposit_bridge_pure_advance_reconciles_green(): void
    {
        // The Task-24 BLOCKER scenario: a plain customer deposit with NO open
        // invoices (pure advance). Cash moves; the only GL consequence is the
        // 419 customer-advance entry. The bridge must record a movement carrying
        // that JE, and the repository must reconcile GREEN — never freeze.
        [$tenant, $repository, $event] = $this->realDepositScenario('50.00');

        app(CompanyContext::class)->clear();
        $this->app->make(TreasuryDepositBridge::class)->apply($event);

        // The recorded movement carries a (posted) journal entry — not null.
        $movement = DB::table('repository_movements')->where('source_id', $event->id)->first();
        self::assertNotNull($movement);
        self::assertNotNull($movement->journal_entry_id, 'A pure-advance deposit movement must carry the customer-advance JE.');

        $exit = Artisan::call('treasury:reconcile', ['--tenant' => $tenant->id]);

        self::assertSame(0, $exit, 'A real pure-advance deposit must reconcile GREEN.');
        $repository->refresh();
        self::assertNull($repository->frozen_at, 'A pure-advance deposit must NOT freeze the drawer.');
        self::assertSame(0, $this->driftEventCount($repository->id));
    }

    public function test_real_account_payment_bridge_null_actor_pure_advance_reconciles_green(): void
    {
        // The deeper leak the excess>0 recon fix missed: a device-authored
        // ACCOUNT_PAYMENT whose cashier is NOT a resolvable company member
        // (offline-degraded — the bridge tolerates it, created_by=null). Cash
        // moves; before Fix A the advance/AR GL was skipped (actor-gated), so the
        // movement carried a null journal_entry_id and reconcile FROZE the drawer.
        // Fix A posts the GL consequence as a SYSTEM-generated entry, so it must
        // now reconcile GREEN.
        [$tenant, $repository, $event] = $this->realAccountPaymentScenario('100.000', resolvableCashier: false);

        app(CompanyContext::class)->clear();
        $this->app->make(TreasuryAccountPaymentBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        self::assertNull($payment->created_by, 'This scenario exercises the null-actor (unresolved cashier) path.');
        self::assertNotNull($payment->journal_entry_id, 'A null-actor account payment must still link a system-posted JE.');

        $movement = DB::table('repository_movements')->where('source_id', $event->id)->first();
        self::assertNotNull($movement);
        self::assertNotNull($movement->journal_entry_id, 'The cash movement must carry the (system-posted) JE.');

        $exit = Artisan::call('treasury:reconcile', ['--tenant' => $tenant->id]);

        self::assertSame(0, $exit, 'A null-actor pure-advance account payment must reconcile GREEN.');
        $repository->refresh();
        self::assertNull($repository->frozen_at, 'A null-actor account payment must NOT freeze the drawer.');
        self::assertSame(0, $this->driftEventCount($repository->id));
    }

    public function test_deposit_bridge_guard_refuses_null_je_movement_when_repository_has_no_gl_account(): void
    {
        // Fix 2 (belt-and-suspenders): if — despite Fix 1 — the allocation cannot
        // link a JE (a repository with no gl_account_id has no GL to post to), the
        // bridge must FAIL LOUD rather than record a null-JE movement the reconcile
        // would later freeze on. No movement (and no Payment) survives the throw.
        [$tenant, $repository, $event] = $this->realDepositScenario('40.00', glLinked: false);

        app(CompanyContext::class)->clear();

        $this->expectException(\DomainException::class);
        try {
            $this->app->make(TreasuryDepositBridge::class)->apply($event);
        } finally {
            self::assertSame(
                0,
                DB::table('repository_movements')->where('source_id', $event->id)->count(),
                'The guard must refuse BEFORE recording a null-JE movement.',
            );
            self::assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
            $repository->refresh();
            self::assertNull($repository->frozen_at);
        }
    }

    // ── real-bridge scenario builders ──────────────────────────────────────────

    /**
     * A GL-linked company (chart of accounts + explicit CustomerAdvance/419) with
     * one cash-register repository, on a FRESH tenant so treasury:reconcile can be
     * targeted at it in isolation.
     *
     * @return array{Tenant, Company, User, Location, PaymentRepository}
     */
    private function seedGlCompany(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        app(CompanyContext::class)->setCompanyId($company->id);

        $location = Location::factory()->create(['company_id' => $company->id]);

        $cashier = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cashier']);

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'code' => 'CASH', 'name' => 'Cash',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company->refresh());
        if (Account::findByPurpose($company->id, SystemAccountPurpose::CustomerAdvance) === null) {
            Account::factory()->liability()->create([
                'tenant_id' => $tenant->id, 'company_id' => $company->id, 'code' => 'ADV-419',
                'name' => 'Customer Advances', 'system_purpose' => SystemAccountPurpose::CustomerAdvance, 'is_active' => true,
            ]);
        }

        $cashAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'TND',
            'next_movement_ordinal' => 0,
            'frozen_at' => null,
            'frozen_reason' => null,
            'balance' => '0.000',
        ]);

        return [$tenant, $company, $cashier, $location, $repository];
    }

    /**
     * Author a REAL pure-advance DEPOSIT_RECEIPT through the server-side service
     * (canonical payload shape) and return [tenant, repository, event].
     *
     * @param  numeric-string  $amount
     * @return array{Tenant, PaymentRepository, FiscalEvent}
     */
    private function realDepositScenario(string $amount, bool $glLinked = true): array
    {
        [$tenant, $company, $cashier, , $repository] = $this->seedGlCompany();

        if (! $glLinked) {
            // A mis-configured legacy repository: it resolves (carries the cash GL
            // account via the legacy account_id) but has NO gl_account_id, so the
            // allocation service cannot post GL — the guard must refuse the movement.
            $repository->update(['account_id' => $repository->gl_account_id, 'gl_account_id' => null]);
            $repository->refresh();
        }

        // A resolved, active company member — a back-office deposit is recorded by
        // an authenticated actor (the deposit bridge rejects a null actor).
        $this->grantMembership($cashier->id, $company->id);

        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Deposit Customer',
        ]);

        $event = $this->app->make(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $cashier->id,
            actorName: (string) $cashier->name,
            currencyCode: 'TND',
            amount: $amount,
            methodCode: 'CASH',
            repositoryId: $repository->id,
            notes: null,
        );

        return [$tenant, $repository, $event];
    }

    /**
     * Author a REAL device ACCOUNT_PAYMENT for a customer with NO open invoices
     * (pure advance). When $resolvableCashier is false the cashier is deliberately
     * NOT a company member, so the bridge resolves a null actor (the offline-
     * degraded path). Returns [tenant, repository, event].
     *
     * @param  numeric-string  $amount
     * @return array{Tenant, PaymentRepository, FiscalEvent}
     */
    private function realAccountPaymentScenario(string $amount, bool $resolvableCashier): array
    {
        [$tenant, $company, $cashier, , $repository] = $this->seedGlCompany();

        if ($resolvableCashier) {
            $this->grantMembership($cashier->id, $company->id);
        }

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Account Customer',
        ]);

        $businessDate = now()->toDateString();
        $payload = [
            'account_payment_uuid' => (string) Str::uuid(),
            'business_date' => $businessDate,
            'cashier_id' => $cashier->id,
            'cashier_name' => (string) $cashier->name,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null, 'customer_category' => 'retail', 'customer_id' => $customer->id,
                'customer_sync_status' => 'synced', 'email' => null, 'name' => 'Account Customer',
                'phone' => null, 'tax_number' => null,
            ],
            'event_time_device' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'local_balance_snapshot' => [
                'balance_updated_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'credit_balance_before' => '0.000',
                'net_balance_before' => '0.000', 'payment_amount' => $amount, 'projected_credit_balance_after' => $amount,
                'projected_net_balance_after' => '0.000', 'projected_receivable_balance_after' => '0.000',
                'receivable_balance_before' => '0.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => $amount, 'foreign_currency_amount' => null, 'foreign_currency_code' => null,
                'instrument_serial' => null, 'instrument_type' => null, 'method_code' => 'CASH',
                'repository_id' => $repository->id,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT', 'references' => null, 'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller', 'tax_jurisdiction_country_code' => 'TN', 'tax_number' => '1234567AM000',
            ],
            'shift_id' => (string) Str::uuid(),
            'staleness' => [
                'balance_snapshot_stale' => false, 'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false, 'treasury_allocation_policy' => 'FIFO',
        ];

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'terminal_id' => '33333333-3333-4333-8333-333333333333', 'operator_id' => $cashier->id,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT, 'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1', 'sequence_number' => 7,
            'event_time_device' => now(), 'business_date' => $businessDate,
            'last_server_time_seen' => null, 'server_received_at' => now(),
            'reference_event_id' => null, 'reference_document_id' => null,
            'source_event_class' => 'account_payments', 'source_event_id' => $payload['account_payment_uuid'],
            'partner_id' => null, 'partner_identity_snapshot' => null,
            'canonical_bytes' => (string) json_encode($payload), 'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired, 'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null, 'integrity_exception_reason' => null,
            'payload' => $payload, 'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();

        return [$tenant, $repository, $event];
    }

    private function grantMembership(string $userId, string $companyId): void
    {
        UserCompanyMembership::query()->create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);
    }

    private function createTreasuryManager(Company $company): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->tenant_id);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('treasury.manage', 'sanctum');

        $user = User::factory()->create(['tenant_id' => $company->tenant_id]);
        $user->givePermissionTo('treasury.manage');
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        return $user;
    }

    private function statement(
        PaymentRepository $repository,
        BankStatementStatus $status,
        \DateTimeInterface $importedAt,
        string $openingBalance = '0.000',
        string $closingBalance = '0.000',
    ): BankStatement {
        return BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $repository->id,
            'currency' => $repository->currency,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'status' => $status,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/reconcile-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => null,
            'imported_at' => $importedAt,
        ]);
    }
}

/**
 * Test-only decorator that forces {@see CurrencyScaleResolverInterface::getScaleSafe()}
 * to throw for one specific currency code while delegating everything else (and
 * the strict {@see CurrencyScaleResolverInterface::getScale()}) to the real
 * resolver. `getScaleSafe()` is designed to never throw in production (it always
 * falls through to the static ISO 4217 map for an explicit currency code); this
 * decorator simulates a genuine check-time failure (2026-07-09 audit N1 —
 * "advisory/row-lock timeout, audit-write failure, corrupt row") for a single
 * repository so the per-repository isolation contract can be exercised without
 * depending on driver-specific corruption tricks.
 */
final class ThrowingScaleResolverDecorator implements CurrencyScaleResolverInterface
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $inner,
        private readonly string $throwForCurrency,
    ) {}

    public function getScale(?string $currencyCode = null): int
    {
        return $this->inner->getScale($currencyCode);
    }

    public function getScaleSafe(?string $currencyCode = null, int $fallback = 3): int
    {
        if ($currencyCode === $this->throwForCurrency) {
            throw new \RuntimeException('simulated scale-resolution failure for currency '.$this->throwForCurrency);
        }

        return $this->inner->getScaleSafe($currencyCode, $fallback);
    }
}
