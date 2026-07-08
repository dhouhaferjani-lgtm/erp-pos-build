<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 12 (Treasury Money-Movement Spine, Wave C): TreasuryMovementService::transfer()
 * writes a paired out/in movement across two repositories inside ONE dedicated
 * transaction. It is concurrency-critical: it takes the GL company advisory
 * lock FIRST, then locks BOTH repositories sorted by id (deadlock-free), then
 * writes both legs sharing a transfer_group_id, and posts exactly one GL entry
 * only when the two repositories map to different gl_account_id.
 */
final class TreasuryMovementServiceTransferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Rule 20: the port runs with NO CompanyContext in queued/fiscal
        // contexts — clear it so scale resolution is exercised via the explicit
        // intent currency, matching the worker reality.
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    private function seedRepository(
        string $balance = '0.000',
        string $currency = 'TND',
        ?string $glAccountId = null,
    ): PaymentRepository {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => $currency,
            'balance' => $balance,
            'next_movement_ordinal' => 0,
            'gl_account_id' => $glAccountId,
        ]);
    }

    private function seedAccount(string $code): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => "Cash {$code}",
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
    }

    private function seedUser(): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer Actor',
            'email' => 'transfer-actor-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * A balanced Draft journal entry (Dr one cash account / Cr another) the
     * transfer can seal via postEntryNow when the two repositories cross GL
     * accounts.
     */
    private function makeDraftTransferEntry(Account $debit, Account $credit, string $amount): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'TRF-'.Str::upper(Str::random(8)),
            'entry_date' => now(),
            'description' => 'Inter-repository transfer',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debit->id,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Transfer in',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $credit->id,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Transfer out',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }

    /**
     * @param  numeric-string  $amount
     */
    private function intent(
        PaymentRepository $from,
        PaymentRepository $to,
        string $amount = '30.000',
        string $currency = 'TND',
        ?string $journalEntryId = null,
        ?string $createdBy = null,
        ?string $groupId = null,
    ): TransferIntent {
        return new TransferIntent(
            fromRepositoryId: $from->id,
            toRepositoryId: $to->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: $amount,
            currency: $currency,
            transferGroupId: $groupId ?? (string) Str::uuid(),
            journalEntryId: $journalEntryId,
            occurredAt: null,
            createdBy: $createdBy,
            notes: 'drawer to safe',
        );
    }

    // (a) transfer 30 A(100) -> B(0): A=70, B=30; two legs share transfer_group_id;
    //     each repo ordinal -> 1; out leg Out, in leg In.
    public function test_transfer_moves_funds_across_repositories_with_paired_legs(): void
    {
        $from = $this->seedRepository(balance: '100.000');
        $to = $this->seedRepository(balance: '0.000');
        $intent = $this->intent($from, $to, amount: '30.000');

        $result = $this->service()->transfer($intent);

        $from->refresh();
        $to->refresh();
        $this->assertSame('70.000', $from->balance);
        $this->assertSame('30.000', $to->balance);
        $this->assertSame(1, $from->next_movement_ordinal);
        $this->assertSame(1, $to->next_movement_ordinal);

        // Result shape.
        $this->assertSame('70.000', $result->outLeg->balanceAfter);
        $this->assertSame('30.000', $result->inLeg->balanceAfter);
        $this->assertSame(1, $result->outLeg->ordinal);
        $this->assertSame(1, $result->inLeg->ordinal);
        $this->assertFalse($result->outLeg->wasIdempotentHit);
        $this->assertFalse($result->inLeg->wasIdempotentHit);

        $outLeg = RepositoryMovement::findOrFail($result->outLeg->movementId);
        $inLeg = RepositoryMovement::findOrFail($result->inLeg->movementId);

        $this->assertSame(MovementDirection::Out, $outLeg->direction);
        $this->assertSame(MovementDirection::In, $inLeg->direction);
        $this->assertSame($from->id, $outLeg->payment_repository_id);
        $this->assertSame($to->id, $inLeg->payment_repository_id);

        // Both legs share the transfer_group_id.
        $this->assertSame($intent->transferGroupId, $outLeg->transfer_group_id);
        $this->assertSame($intent->transferGroupId, $inLeg->transfer_group_id);

        // Legs net to zero.
        $this->assertSame('30.000', $outLeg->amount);
        $this->assertSame('30.000', $inLeg->amount);

        // Exactly two movement rows for this group.
        $this->assertSame(2, RepositoryMovement::where('transfer_group_id', $intent->transferGroupId)->count());
    }

    public function test_transfer_dispatches_recorded_event_for_both_legs(): void
    {
        Event::fake([RepositoryMovementRecorded::class]);

        $from = $this->seedRepository(balance: '100.000');
        $to = $this->seedRepository(balance: '0.000');
        $intent = $this->intent($from, $to, amount: '30.000');

        $this->service()->transfer($intent);

        Event::assertDispatchedTimes(RepositoryMovementRecorded::class, 2);
        Event::assertDispatched(
            RepositoryMovementRecorded::class,
            fn (RepositoryMovementRecorded $e): bool => $e->direction === MovementDirection::Out
                && $e->transferGroupId === $intent->transferGroupId,
        );
        Event::assertDispatched(
            RepositoryMovementRecorded::class,
            fn (RepositoryMovementRecorded $e): bool => $e->direction === MovementDirection::In
                && $e->transferGroupId === $intent->transferGroupId,
        );
    }

    // Currency mismatch across the two repositories throws.
    public function test_currency_mismatch_throws(): void
    {
        $from = $this->seedRepository(balance: '100.000', currency: 'TND');
        $to = $this->seedRepository(balance: '0.000', currency: 'EUR');

        $this->expectException(CurrencyMismatchException::class);

        $this->service()->transfer($this->intent($from, $to, currency: 'TND'));
    }

    // (c) A failure on the second leg rolls back BOTH — no half-transfer.
    public function test_failure_on_second_leg_rolls_back_both(): void
    {
        $from = $this->seedRepository(balance: '100.000');
        $to = $this->seedRepository(balance: '0.000');
        $groupId = (string) Str::uuid();

        // Poison the in-leg: pre-seed a row carrying the exact idempotency_key
        // the in-leg will attempt, so the second insert violates the unique
        // idempotency_key index and the whole dedicated transaction rolls back.
        DB::table('repository_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $to->id,
            'direction' => MovementDirection::In->value,
            'amount' => '1.000',
            'currency' => 'TND',
            'balance_after' => '1.000',
            'ordinal' => 0, // does not collide with the in-leg's computed ordinal (1)
            'source_type' => 'transfer',
            'source_id' => $groupId,
            'idempotency_key' => "transfer:{$groupId}:in",
            'occurred_at' => now(),
        ]);

        try {
            $this->service()->transfer($this->intent($from, $to, amount: '30.000', groupId: $groupId));
            $this->fail('Expected a QueryException from the poisoned in-leg.');
        } catch (QueryException) {
            // expected
        }

        // No out-leg survived on the source repository.
        $this->assertSame(0, RepositoryMovement::where('payment_repository_id', $from->id)->count());
        // The destination still has ONLY the pre-seeded poison row (no in-leg).
        $this->assertSame(1, RepositoryMovement::where('payment_repository_id', $to->id)->count());

        // Both balances and ordinals untouched.
        $from->refresh();
        $to->refresh();
        $this->assertSame('100.000', $from->balance);
        $this->assertSame('0.000', $to->balance);
        $this->assertSame(0, $from->next_movement_ordinal);
        $this->assertSame(0, $to->next_movement_ordinal);
    }

    // Same GL account on both repositories -> transfer posts NO journal entry.
    public function test_same_gl_account_posts_no_journal_entry(): void
    {
        $cash = $this->seedAccount('531001');
        $from = $this->seedRepository(balance: '100.000', glAccountId: $cash->id);
        $to = $this->seedRepository(balance: '0.000', glAccountId: $cash->id);

        // A Draft entry is supplied but MUST NOT be posted (no net GL effect).
        $draft = $this->makeDraftTransferEntry($cash, $cash, '30.000');

        $result = $this->service()->transfer(
            $this->intent($from, $to, amount: '30.000', journalEntryId: $draft->id),
        );

        // The entry stays Draft — nothing was posted.
        $this->assertSame(JournalEntryStatus::Draft, JournalEntry::findOrFail($draft->id)->status);
        $this->assertSame(0, JournalEntry::where('status', JournalEntryStatus::Posted)->count());

        // Same-GL-account transfer legs carry NULL journal_entry_id (spec exemption).
        $this->assertNull(RepositoryMovement::findOrFail($result->outLeg->movementId)->journal_entry_id);
        $this->assertNull(RepositoryMovement::findOrFail($result->inLeg->movementId)->journal_entry_id);
    }

    // Different GL accounts -> transfer posts exactly ONE journal entry, attached to both legs.
    public function test_different_gl_account_posts_exactly_one_journal_entry(): void
    {
        $drawerCash = $this->seedAccount('531001');
        $bankCash = $this->seedAccount('512001');
        $from = $this->seedRepository(balance: '100.000', glAccountId: $drawerCash->id);
        $to = $this->seedRepository(balance: '0.000', glAccountId: $bankCash->id);
        $user = $this->seedUser();

        $draft = $this->makeDraftTransferEntry($bankCash, $drawerCash, '30.000');

        $result = $this->service()->transfer(
            $this->intent($from, $to, amount: '30.000', journalEntryId: $draft->id, createdBy: $user->id),
        );

        // Exactly one posted JE — the supplied draft.
        $this->assertSame(JournalEntryStatus::Posted, JournalEntry::findOrFail($draft->id)->status);
        $this->assertSame(1, JournalEntry::where('status', JournalEntryStatus::Posted)->count());

        // Both legs point at the posted entry.
        $this->assertSame($draft->id, RepositoryMovement::findOrFail($result->outLeg->movementId)->journal_entry_id);
        $this->assertSame($draft->id, RepositoryMovement::findOrFail($result->inLeg->movementId)->journal_entry_id);
    }

    // (b) Sorted-lock-order / deadlock-freedom, asserted STRUCTURALLY: opposing
    //     transfers (A->B and B->A) both acquire the two repository row locks in
    //     the SAME id-sorted order. pgsql-only (sqlite emits no `for update`).
    public function test_repositories_are_locked_in_sorted_id_order_regardless_of_direction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-level FOR UPDATE locking is a Postgres concept; sqlite emits no lock clause to assert on.');
        }

        $a = $this->seedRepository(balance: '100.000');
        $b = $this->seedRepository(balance: '100.000');
        $sortedExpected = [$a->id, $b->id];
        sort($sortedExpected);

        // Transfer A -> B.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service()->transfer($this->intent($a, $b, amount: '10.000'));
        $forwardOrder = $this->lockedRepositoryIdsInOrder(DB::getQueryLog());
        DB::disableQueryLog();

        // Transfer B -> A (opposing direction, same pair).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service()->transfer($this->intent($b, $a, amount: '10.000'));
        $reverseOrder = $this->lockedRepositoryIdsInOrder(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($sortedExpected, $forwardOrder, 'A->B must lock repositories in id-sorted order.');
        $this->assertSame($sortedExpected, $reverseOrder, 'B->A must lock repositories in the SAME id-sorted order.');
    }

    /**
     * Extract, in issue order, the repository ids from the `... for update`
     * SELECTs against payment_repositories. The row-id is the LAST binding of
     * each lock query (tenant_id, company_id, id).
     *
     * @param  array<int, array{query: string, bindings: array<int, mixed>}>  $log
     * @return array<int, string>
     */
    private function lockedRepositoryIdsInOrder(array $log): array
    {
        $ids = [];
        foreach ($log as $entry) {
            $query = strtolower($entry['query']);
            if (! str_contains($query, 'payment_repositories') || ! str_contains($query, 'for update')) {
                continue;
            }
            $bindings = $entry['bindings'];
            $ids[] = (string) end($bindings);
        }

        return $ids;
    }
}
