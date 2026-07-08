<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Treasury\Domain\Exceptions\IdempotencyConflictException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 11 (Treasury Money-Movement Spine, Wave C): TreasuryMovementService::record()
 * is the single write port every money movement converges onto. It is
 * concurrency- and idempotency-critical.
 *
 * Idempotency recovery (cases c/d) is Postgres-shaped — a duplicate insert
 * poisons a pg transaction until rollback-to-savepoint. Those two cases run on
 * both drivers here; the remaining cases (a/b/e/f) are driver-agnostic.
 */
final class TreasuryMovementServiceRecordTest extends TestCase
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

    private function seedRepository(string $currency = 'TND', string $balance = '100.000', ?string $frozenAt = null): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => $currency,
            'balance' => $balance,
            'next_movement_ordinal' => 0,
            'frozen_at' => $frozenAt,
            'frozen_reason' => $frozenAt !== null ? 'end_of_day_count' : null,
        ]);
    }

    /**
     * A posted journal entry the movement can point at. journal_entry_id is a
     * real FK, so seed a minimal row rather than a random UUID.
     */
    private function postedJournalEntryId(): string
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.Str::upper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'description' => 'spine test entry',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  numeric-string  $amount
     */
    private function intent(
        PaymentRepository $repo,
        MovementDirection $direction = MovementDirection::In,
        string $amount = '25.000',
        string $currency = 'TND',
        ?string $sourceId = null,
        ?string $journalEntryId = null,
        bool $allowWhileFrozen = false,
    ): MovementIntent {
        return new MovementIntent(
            repositoryId: $repo->id,
            tenantId: $repo->tenant_id,
            companyId: $repo->company_id,
            direction: $direction,
            amount: $amount,
            currency: $currency,
            sourceType: MovementSourceType::Payment,
            sourceId: $sourceId ?? (string) Str::uuid(),
            idempotencyLeg: 'main',
            journalEntryId: $journalEntryId,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: $allowWhileFrozen,
        );
    }

    // (a) records a movement, sets balance_after, increments ordinal, updates cached balance.
    public function test_records_movement_and_increments_ordinal_and_balance(): void
    {
        $repo = $this->seedRepository(currency: 'TND', balance: '100.000');
        $je = $this->postedJournalEntryId();

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '25.000', journalEntryId: $je),
        ));

        $repo->refresh();
        $this->assertSame('125.000', $repo->balance);
        $this->assertSame(1, $result->ordinal);
        $this->assertSame('125.000', $result->balanceAfter);
        $this->assertFalse($result->wasIdempotentHit);

        $movement = RepositoryMovement::findOrFail($result->movementId);
        $this->assertSame(MovementDirection::In, $movement->direction);
        $this->assertSame('25.000', $movement->amount);
        $this->assertSame('125.000', $movement->balance_after);
        $this->assertSame(1, $movement->ordinal);
        $this->assertSame($je, $movement->journal_entry_id);
        $this->assertFalse($movement->recorded_while_frozen);
    }

    public function test_out_movement_decrements_balance(): void
    {
        $repo = $this->seedRepository(balance: '100.000');

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, direction: MovementDirection::Out, amount: '40.000'),
        ));

        $this->assertSame('60.000', $result->balanceAfter);
        $repo->refresh();
        $this->assertSame('60.000', $repo->balance);
    }

    // (a-bonus) the RepositoryMovementRecorded event fires exactly once, after commit.
    public function test_dispatches_recorded_event_exactly_once(): void
    {
        Event::fake([RepositoryMovementRecorded::class]);

        $repo = $this->seedRepository();

        $result = DB::transaction(fn () => $this->service()->record($this->intent($repo)));

        Event::assertDispatchedTimes(RepositoryMovementRecorded::class, 1);
        Event::assertDispatched(
            RepositoryMovementRecorded::class,
            fn (RepositoryMovementRecorded $e): bool => $e->movementId === $result->movementId
                && $e->ordinal === 1
                && $e->balanceAfter === '125.000',
        );
    }

    // (b) currency mismatch throws.
    public function test_currency_mismatch_throws(): void
    {
        $repo = $this->seedRepository(currency: 'TND');

        $this->expectException(CurrencyMismatchException::class);

        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, currency: 'EUR'),
        ));
    }

    // (e) frozen repo: allowWhileFrozen=false throws; =true succeeds with recorded_while_frozen=true.
    public function test_frozen_repository_rejects_interactive_movement(): void
    {
        $repo = $this->seedRepository(frozenAt: now()->toDateTimeString());

        $this->expectException(RepositoryFrozenException::class);

        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, allowWhileFrozen: false),
        ));
    }

    public function test_frozen_repository_allows_replay_and_marks_recorded_while_frozen(): void
    {
        $repo = $this->seedRepository(frozenAt: now()->toDateTimeString());

        $result = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, allowWhileFrozen: true),
        ));

        $this->assertFalse($result->wasIdempotentHit);
        $this->assertSame(1, $result->ordinal);

        $movement = RepositoryMovement::findOrFail($result->movementId);
        $this->assertTrue($movement->recorded_while_frozen);
    }

    // (f) MED-9: no active transaction throws LogicException.
    public function test_record_without_active_transaction_throws(): void
    {
        $repo = $this->seedRepository();
        $intent = $this->intent($repo);

        // RefreshDatabase wraps each test in a transaction (level starts at 1);
        // pop it so we are genuinely at transactionLevel 0, then restore a
        // fresh wrapper for RefreshDatabase teardown. record() throws at MED-9
        // (step 0) before touching the DB, so no row/seed is consulted here.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->expectException(\LogicException::class);
            $this->service()->record($intent);
        } finally {
            DB::beginTransaction(); // restore wrapper for RefreshDatabase teardown
        }
    }

    // (c) idempotency: same intent twice in SEPARATE transactions → one row, second is a hit, no gap.
    public function test_idempotent_replay_returns_existing_movement_without_ordinal_gap(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Idempotency savepoint recovery is Postgres-shaped (duplicate-insert poisons the txn until rollback-to-savepoint).');
        }

        $repo = $this->seedRepository(balance: '100.000');
        $sourceId = (string) Str::uuid();

        $first = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '25.000', sourceId: $sourceId),
        ));

        $second = DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '25.000', sourceId: $sourceId),
        ));

        $this->assertFalse($first->wasIdempotentHit);
        $this->assertTrue($second->wasIdempotentHit);
        $this->assertSame($first->movementId, $second->movementId);
        $this->assertSame($first->ordinal, $second->ordinal);
        $this->assertSame($first->balanceAfter, $second->balanceAfter);

        // Exactly one row; ordinal advanced exactly once; balance unchanged by the replay.
        $this->assertSame(1, RepositoryMovement::where('payment_repository_id', $repo->id)->count());
        $repo->refresh();
        $this->assertSame(1, $repo->next_movement_ordinal);
        $this->assertSame('125.000', $repo->balance);
    }

    // (d) idempotency mismatch: same key, different amount → throws loudly.
    public function test_idempotency_conflict_on_different_amount_throws(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Idempotency savepoint recovery is Postgres-shaped.');
        }

        $repo = $this->seedRepository(balance: '100.000');
        $sourceId = (string) Str::uuid();

        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '25.000', sourceId: $sourceId),
        ));

        $this->expectException(IdempotencyConflictException::class);

        DB::transaction(fn () => $this->service()->record(
            $this->intent($repo, amount: '99.000', sourceId: $sourceId),
        ));
    }
}
