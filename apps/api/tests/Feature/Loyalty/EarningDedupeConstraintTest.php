<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Loyalty\Application\Services\EarningProcessingService;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;

/**
 * Task 7 (LB-4) — atomic earn dedupe via dedicated source columns +
 * partial unique index `loyalty_txn_earn_source_unique`.
 *
 * Closes the TOCTOU between the two queued earn paths (fiscal projection +
 * EarnPointsOnReceiptCompleted listener) that could double-credit the same
 * receipt.
 */
final class EarningDedupeConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveSpendProgram(string $tenantId, string $rate = '1'): LoyaltyProgram
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $tenantId,
            'status' => ProgramStatus::Active,
        ]);
        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Spend,
            'reward_value' => $rate,
            'is_active' => true,
            'conditions' => [],
        ]);

        return $program;
    }

    private function enrollContactMember(string $tenantId, string $programId, string $contactId): Enrollment
    {
        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $tenantId,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $contactId,
        ]);

        return Enrollment::factory()->create([
            'program_id' => $programId,
            'member_id' => $member->id,
            'status' => EnrollmentStatus::Active,
            'current_balance' => '0.000',
        ]);
    }

    private function service(): EarningProcessingService
    {
        return app(EarningProcessingService::class);
    }

    /**
     * Fabricate a PG-shaped QueryException with the given SQLSTATE and a message
     * that carries the constraint name (as PG surfaces it). Mirrors how
     * QueryException derives errorInfo/message from the previous PDOException.
     */
    private function makeQueryException(string $sqlState, string $constraintMessage): QueryException
    {
        $previous = new PDOException("SQLSTATE[{$sqlState}]: Unique violation: 7 ERROR: {$constraintMessage}");
        $previous->errorInfo = [$sqlState, 7, $constraintMessage];

        return new QueryException(
            'tenant',
            'insert into "loyalty_transactions" (...) values (...)',
            [],
            $previous,
        );
    }

    public function test_concurrent_style_duplicate_insert_hits_unique_constraint(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $this->service()->earnPoints(
            $enrollment->id,
            ['amount' => '12.000', 'currency' => 'TND'],
            'pos_receipt',
            $sourceId,
        );

        // Bypass the pre-check: insert a second earn row directly with the same
        // (enrollment_id, source_type, source_id) → the partial unique index must fire.
        $this->expectException(QueryException::class);

        $dup = new Transaction([
            'enrollment_id' => $enrollment->id,
            'transaction_type' => TransactionType::Earn,
            'amount' => '12.000',
            'balance_before' => '12.000',
            'balance_after' => '24.000',
            'description' => 'racing duplicate',
            'source_type' => 'pos_receipt',
            'source_id' => $sourceId,
            'metadata' => ['source_type' => 'pos_receipt', 'source_id' => $sourceId],
            'created_at' => now(),
        ]);
        $dup->save();
    }

    public function test_translate_earn_duplicate_converts_constraint_violation(): void
    {
        $service = $this->service();

        // (a) 23505 + our index name → InvalidArgumentException with 'already earned'.
        $onIndex = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "loyalty_txn_earn_source_unique"',
        );
        $result = $service->translateEarnDuplicate($onIndex, 'pos_receipt', 'receipt-a');
        self::assertInstanceOf(InvalidArgumentException::class, $result);
        self::assertStringContainsString('already earned', $result->getMessage());
        self::assertStringContainsString('pos_receipt', $result->getMessage());
        self::assertStringContainsString('receipt-a', $result->getMessage());

        // (b) 23505 but a DIFFERENT constraint → null (rethrown as-is by caller).
        $onOther = $this->makeQueryException(
            '23505',
            'duplicate key value violates unique constraint "some_other_unique"',
        );
        self::assertNull($service->translateEarnDuplicate($onOther, 'pos_receipt', 'receipt-a'));

        // (c) non-23505 (foreign-key violation) → null.
        $fk = $this->makeQueryException(
            '23503',
            'insert or update on table "loyalty_transactions" violates foreign key constraint',
        );
        self::assertNull($service->translateEarnDuplicate($fk, 'pos_receipt', 'receipt-a'));
    }

    public function test_duplicate_earn_via_earn_points_still_yields_already_earned_and_one_row(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $service = $this->service();
        $service->earnPoints($enrollment->id, ['amount' => '12.000', 'currency' => 'TND'], 'pos_receipt', $sourceId);

        try {
            $service->earnPoints($enrollment->id, ['amount' => '12.000', 'currency' => 'TND'], 'pos_receipt', $sourceId);
            self::fail('Expected InvalidArgumentException for duplicate earn');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('already earned', $e->getMessage());
        }

        self::assertSame(
            1,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
        );
    }

    public function test_source_columns_written_and_backfill_query_shape(): void
    {
        $tenantId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $program = $this->seedActiveSpendProgram($tenantId, '1');
        $enrollment = $this->enrollContactMember($tenantId, $program->id, $contactId);

        $sourceId = (string) Str::uuid();
        $this->service()->earnPoints(
            $enrollment->id,
            ['amount' => '12.000', 'currency' => 'TND'],
            'pos_receipt',
            $sourceId,
        );

        /** @var Transaction $row */
        $row = Transaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->firstOrFail();

        self::assertSame('pos_receipt', $row->source_type);
        self::assertSame($sourceId, $row->source_id);
        // Metadata still carries the refs (audit intact).
        self::assertSame('pos_receipt', $row->metadata['source_type'] ?? null);
        self::assertSame($sourceId, $row->metadata['source_id'] ?? null);
    }
}
