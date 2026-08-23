<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\RedemptionProcessingService;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Loyalty\Domain\Repositories\EnrollmentRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TransactionRepositoryInterface;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentEnrollmentRepository;
use App\Modules\Loyalty\Infrastructure\Repositories\EloquentTransactionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lane Q-3 (Session B) — loyalty reward redemption double-spend.
 *
 * The POS retail-till sub-report (2026-08-23) recorded the redeem path as an
 * unlocked absolute-value balance write: the enrollment was read OUTSIDE the
 * transaction and the new balance written as `bcsub($staleBalance, $cost)`,
 * with no lock, no EnrollmentStatus guard and no redemption idempotency key.
 * Two racing redemptions would each have computed `100 - 10 = 90` from the same
 * stale snapshot — two rewards handed out, one debit taken, and both ledger
 * rows internally consistent so nothing surfaced the drift.
 *
 * WOULD have: the defect was never LIVE (treasury gate r1, F-1). This method
 * 500'd at insert on every call it ever received — `loyalty_transactions
 * .created_at` is NOT NULL with no default and `Transaction::$timestamps` is
 * false — so no production redemption has ever committed and there is no
 * historical drift to remediate. These tests pin the behaviour of a path this
 * lane ARMS rather than one it repairs, and the endpoint in front of it stays
 * refused (LoyaltyPOSControllerTest::
 * test_redeem_endpoint_refuses_until_the_client_contract_is_wired) until the
 * client contract exists.
 *
 * @group loyalty
 */
final class RedemptionDoubleSpendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Enrollment, 1: Reward}
     */
    private function scaffold(
        string $balance = '10.000',
        EnrollmentStatus $status = EnrollmentStatus::Active,
        string $pointsCost = '10.000',
    ): array {
        $tenantId = (string) Str::uuid();

        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);

        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $enrollment = Enrollment::factory()->create([
            'program_id' => $program->id,
            'member_id' => $member->id,
            'status' => $status,
            'current_balance' => $balance,
            'lifetime_earned' => '100.000',
            'lifetime_redeemed' => '0.000',
        ]);

        /** @var Reward $reward */
        $reward = Reward::create([
            'program_id' => $program->id,
            'name' => 'Free coffee',
            'reward_type' => RewardType::FreeItem,
            'points_cost' => $pointsCost,
            'reward_value' => '3.000',
            'is_active' => true,
        ]);

        return [$enrollment, $reward];
    }

    private function service(): RedemptionProcessingService
    {
        return app(RedemptionProcessingService::class);
    }

    private function redeemRowCount(string $enrollmentId): int
    {
        return Transaction::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('transaction_type', TransactionType::Redeem)
            ->count();
    }

    private function balanceOf(string $enrollmentId): string
    {
        /** @var object{current_balance: string} $row */
        $row = DB::table('loyalty_enrollments')->where('id', $enrollmentId)->first(['current_balance']);

        return (string) $row->current_balance;
    }

    // ------------------------------------------------------------------
    // (a)+(b) locked read + conditional decrement
    // ------------------------------------------------------------------

    /**
     * The defect, deterministically: the service is handed an enrollment model
     * whose in-memory balance (10) no longer matches the committed row (0),
     * exactly as a concurrent redemption that won the race would leave it.
     *
     * Old behaviour: the absolute write `bcsub(10, 10)` lands, a redeem ledger
     * row is created and the concurrent debit is silently lost.
     * Required behaviour: the conditional decrement matches zero rows and the
     * redemption is refused — no ledger row, nothing committed.
     */
    public function test_stale_balance_premise_is_refused_by_the_conditional_decrement(): void
    {
        [$enrollment, $reward] = $this->scaffold('10.000');

        $this->app->instance(
            EnrollmentRepositoryInterface::class,
            new DrainingEnrollmentRepository(
                new EloquentEnrollmentRepository,
                $enrollment->id,
            ),
        );

        try {
            $this->service()->redeemReward($enrollment->id, $reward->id);
            self::fail('Expected the redemption to be refused against the drained (locked) row');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Insufficient points', $e->getMessage());
        }

        self::assertSame(0, $this->redeemRowCount($enrollment->id), 'no redeem ledger row may be written');
    }

    /**
     * Happy-path regression: balance_before / balance_after must be computed
     * from the row the service actually debited.
     */
    public function test_happy_path_redemption_debits_once_with_correct_ledger_balances(): void
    {
        [$enrollment, $reward] = $this->scaffold('25.000', EnrollmentStatus::Active, '10.000');

        $result = $this->service()->redeemReward($enrollment->id, $reward->id);

        self::assertSame(TransactionType::Redeem, $result->transaction_type);
        self::assertSame(0, bccomp($result->amount, '-10', 3), "amount was {$result->amount}");
        self::assertSame(0, bccomp($result->balance_before, '25', 3), "balance_before was {$result->balance_before}");
        self::assertSame(0, bccomp($result->balance_after, '15', 3), "balance_after was {$result->balance_after}");

        self::assertSame(1, $this->redeemRowCount($enrollment->id));
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '15', 3));

        /** @var Enrollment $fresh */
        $fresh = Enrollment::query()->findOrFail($enrollment->id);
        self::assertSame(0, bccomp((string) $fresh->lifetime_redeemed, '10', 3));
        self::assertNotNull($fresh->last_transaction_at);
    }

    /**
     * Redeeming the exact balance must succeed (the conditional decrement is
     * `>=`, not `>`), and a second redemption of the same reward must then be
     * refused — one success, one 422, final balance 0, one ledger row.
     */
    public function test_second_redemption_of_a_drained_balance_is_refused(): void
    {
        [$enrollment, $reward] = $this->scaffold('10.000');

        $this->service()->redeemReward($enrollment->id, $reward->id);

        try {
            $this->service()->redeemReward($enrollment->id, $reward->id);
            self::fail('Expected the second redemption to be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Insufficient points', $e->getMessage());
        }

        self::assertSame(1, $this->redeemRowCount($enrollment->id));
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '0', 3));
    }

    /**
     * PG-only: the enrollment must be read under a row lock INSIDE the
     * transaction. Mirrors GlChainSequenceConcurrencyTest, which asserts the
     * advisory-lock statement rather than attempting true two-connection
     * contention under RefreshDatabase.
     */
    public function test_enrollment_is_read_for_update_inside_the_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('Row-lock assertion is Postgres-specific.');
        }

        [$enrollment, $reward] = $this->scaffold('25.000');

        DB::enableQueryLog();
        $this->service()->redeemReward($enrollment->id, $reward->id);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $locked = collect($log)->contains(
            fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'loyalty_enrollments')
                && str_contains(strtolower((string) $q['query']), 'for update')
        );

        self::assertTrue($locked, 'the enrollment row must be selected FOR UPDATE inside the redemption transaction');
    }

    // ------------------------------------------------------------------
    // (c) EnrollmentStatus::Active guard
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{0: EnrollmentStatus}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            [EnrollmentStatus::Suspended],
            [EnrollmentStatus::OptedOut],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_enrollment_cannot_redeem(EnrollmentStatus $status): void
    {
        [$enrollment, $reward] = $this->scaffold('50.000', $status);

        try {
            $this->service()->redeemReward($enrollment->id, $reward->id);
            self::fail("Expected a refusal for a {$status->value} enrollment");
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('active', strtolower($e->getMessage()));
        }

        self::assertSame(0, $this->redeemRowCount($enrollment->id));
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '50', 3));
    }

    // ------------------------------------------------------------------
    // (d) redemption idempotency key
    // ------------------------------------------------------------------

    public function test_same_redemption_key_replays_the_original_transaction(): void
    {
        [$enrollment, $reward] = $this->scaffold('25.000', EnrollmentStatus::Active, '10.000');

        $key = 'pos-redeem-'.Str::uuid()->toString();

        $first = $this->service()->redeemReward($enrollment->id, $reward->id, null, $key);
        $second = $this->service()->redeemReward($enrollment->id, $reward->id, null, $key);

        self::assertSame($first->id, $second->id, 'the replay must return the original transaction');
        self::assertSame(1, $this->redeemRowCount($enrollment->id), 'a replay must not write a second ledger row');
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '15', 3), 'a replay must not debit twice');
    }

    public function test_duplicate_redemption_key_insert_violates_the_partial_unique_index(): void
    {
        [$enrollment, $reward] = $this->scaffold('25.000', EnrollmentStatus::Active, '10.000');

        $key = 'pos-redeem-'.Str::uuid()->toString();
        $this->service()->redeemReward($enrollment->id, $reward->id, null, $key);

        // Bypass the service pre-check entirely: the DB index is the backstop.
        $this->expectException(QueryException::class);

        $dup = new Transaction([
            'enrollment_id' => $enrollment->id,
            'transaction_type' => TransactionType::Redeem,
            'amount' => '-10.000',
            'balance_before' => '15.000',
            'balance_after' => '5.000',
            'reward_id' => $reward->id,
            'description' => 'racing duplicate redeem',
            'redemption_key' => $key,
            'metadata' => [],
            'created_at' => now(),
        ]);
        $dup->save();
    }

    /**
     * The 23505/23000 RECOVERY branch, exercised for real on both engines
     * (treasury gate r1, F-4: it was previously untested on any engine).
     *
     * Simulates the one sequence that reaches it: the pre-check looks, finds
     * nothing, and a concurrent redemption commits the same key before this
     * call's INSERT lands. The winner row is pre-committed and the transaction
     * repository is decorated so ONLY the pre-check misses it — the index, the
     * driver's error, the classifier and the recovery lookup are all real.
     */
    public function test_a_lost_idempotency_race_returns_the_winners_transaction(): void
    {
        [$enrollment, $reward] = $this->scaffold('25.000', EnrollmentStatus::Active, '10.000');

        $key = 'pos-redeem-'.Str::uuid()->toString();

        // The concurrent winner, already committed.
        $winner = new Transaction([
            'enrollment_id' => $enrollment->id,
            'transaction_type' => TransactionType::Redeem,
            'amount' => '-10.000',
            'balance_before' => '25.000',
            'balance_after' => '15.000',
            'reward_id' => $reward->id,
            'redemption_key' => $key,
            'description' => 'winner of the race',
            'metadata' => [],
            'created_at' => now(),
        ]);
        $winner->save();

        $this->app->instance(
            TransactionRepositoryInterface::class,
            new RaceLosingTransactionRepository(new EloquentTransactionRepository),
        );

        $result = $this->service()->redeemReward($enrollment->id, $reward->id, null, $key);

        // The loser gets the winner's row back — idempotent, not a 500.
        self::assertSame($winner->id, $result->id);
        self::assertSame(1, $this->redeemRowCount($enrollment->id), 'the loser must not add a row');
        // The loser's conditional debit rolled back with its transaction.
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '25', 3));
    }

    /**
     * The index is partial on `redemption_key IS NOT NULL`: legacy / keyless
     * redemptions must keep working and must not collide with each other.
     */
    public function test_keyless_redemptions_do_not_collide(): void
    {
        [$enrollment, $reward] = $this->scaffold('25.000', EnrollmentStatus::Active, '10.000');

        $this->service()->redeemReward($enrollment->id, $reward->id);
        $this->service()->redeemReward($enrollment->id, $reward->id);

        self::assertSame(2, $this->redeemRowCount($enrollment->id));
        self::assertSame(0, bccomp($this->balanceOf($enrollment->id), '5', 3));
    }
}

/**
 * Test double that makes ONLY the idempotency pre-check miss a row that is
 * already committed — the pre-condition for reaching the 23505/23000 recovery
 * branch. Every other call, including the post-violation recovery lookup,
 * delegates to the real repository.
 *
 * @internal test-only
 */
final class RaceLosingTransactionRepository implements TransactionRepositoryInterface
{
    private int $keyLookups = 0;

    public function __construct(private readonly TransactionRepositoryInterface $inner) {}

    public function findRedeemByIdempotencyKey(string $enrollmentId, string $redemptionKey): ?Transaction
    {
        // Call 1 is the service's pre-check: the winner has "not committed yet".
        // Call 2 is the recovery lookup after the index fires: it sees the truth.
        if ($this->keyLookups++ === 0) {
            return null;
        }

        return $this->inner->findRedeemByIdempotencyKey($enrollmentId, $redemptionKey);
    }

    public function findById(string $id): ?Transaction
    {
        return $this->inner->findById($id);
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function findByEnrollment(string $enrollmentId): Collection
    {
        return $this->inner->findByEnrollment($enrollmentId);
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function findByEnrollmentAndType(string $enrollmentId, TransactionType $type): Collection
    {
        return $this->inner->findByEnrollmentAndType($enrollmentId, $type);
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function findByOrder(string $orderId): Collection
    {
        return $this->inner->findByOrder($orderId);
    }

    public function findBySourceDocument(string $sourceType, string $sourceId): ?Transaction
    {
        return $this->inner->findBySourceDocument($sourceType, $sourceId);
    }

    public function save(Transaction $transaction): Transaction
    {
        return $this->inner->save($transaction);
    }

    public function delete(string $id): bool
    {
        return $this->inner->delete($id);
    }
}

/**
 * Test double that reproduces a lost race deterministically: it reads the
 * enrollment through the real repository (so the caller gets a model carrying
 * the PRE-race balance), then drains the committed row to zero — exactly the
 * state a concurrent redemption that committed first would leave behind.
 *
 * It intercepts BOTH read methods on purpose: `findById` is the pre-fix entry
 * point and `findByIdForUpdate` the post-fix one, so the same test is
 * meaningful against both revisions.
 *
 * @internal test-only
 */
final class DrainingEnrollmentRepository implements EnrollmentRepositoryInterface
{
    public function __construct(
        private readonly EnrollmentRepositoryInterface $inner,
        private readonly string $drainEnrollmentId,
    ) {}

    public function findById(string $id): ?Enrollment
    {
        return $this->drain($this->inner->findById($id));
    }

    public function findByIdForUpdate(string $id): ?Enrollment
    {
        /** @phpstan-ignore-next-line method exists on the post-fix interface */
        return $this->drain($this->inner->findByIdForUpdate($id));
    }

    public function findByMemberAndProgram(string $memberId, string $programId): ?Enrollment
    {
        return $this->inner->findByMemberAndProgram($memberId, $programId);
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public function findByMember(string $memberId): Collection
    {
        return $this->inner->findByMember($memberId);
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public function findByProgram(string $programId): Collection
    {
        return $this->inner->findByProgram($programId);
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        return $this->inner->save($enrollment);
    }

    public function delete(string $id): bool
    {
        return $this->inner->delete($id);
    }

    public function debitForRedemption(string $enrollmentId, string $points, int $scale): bool
    {
        /** @phpstan-ignore-next-line method exists on the post-fix interface */
        return $this->inner->debitForRedemption($enrollmentId, $points, $scale);
    }

    private function drain(?Enrollment $enrollment): ?Enrollment
    {
        if ($enrollment !== null && $enrollment->id === $this->drainEnrollmentId) {
            DB::table('loyalty_enrollments')
                ->where('id', $this->drainEnrollmentId)
                ->update(['current_balance' => '0.000']);
        }

        return $enrollment;
    }
}
