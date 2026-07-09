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
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
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
}
