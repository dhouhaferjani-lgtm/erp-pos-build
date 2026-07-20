<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Application\Services\StatementActionRegistry;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StatementMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
    }

    public function test_manual_allocation_enforces_direction_line_cap_and_state_transition(): void
    {
        $line = $this->line(MovementDirection::In, '100.000');
        $first = $this->movement($this->repository, MovementDirection::In, '40.000');
        $second = $this->movement($this->repository, MovementDirection::In, '60.000');
        $opposite = $this->movement($this->repository, MovementDirection::Out, '10.000');
        $service = app(StatementMatchingService::class);

        $service->allocate($line->id, [['movementId' => $first, 'amount' => '40']], $this->user->id);
        $this->assertSame(BankStatementStatus::Reconciling, $line->statement->fresh()?->status);
        $this->assertSame(StatementLineMatchStatus::Partial, $line->fresh()?->match_status);

        $service->allocate($line->id, [['movementId' => $second, 'amount' => '60.000']], $this->user->id);
        $this->assertSame(StatementLineMatchStatus::Matched, $line->fresh()?->match_status);
        $this->assertDatabaseHas('bank_statement_line_allocations', [
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $first,
            'matched_amount' => '40.000',
            'match_type' => 'manual',
        ]);

        $otherLine = $this->line(MovementDirection::In, '10.000');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('direction');
        $service->allocate($otherLine->id, [['movementId' => $opposite, 'amount' => '10.000']], $this->user->id);
    }

    public function test_line_and_movement_caps_and_repository_ownership_are_enforced(): void
    {
        $service = app(StatementMatchingService::class);
        $oversizedLine = $this->line(MovementDirection::In, '50.000');
        $largeMovement = $this->movement($this->repository, MovementDirection::In, '100.000');
        try {
            $service->allocate($oversizedLine->id, [['movementId' => $largeMovement, 'amount' => '50.001']], $this->user->id);
            $this->fail('Line overallocation must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('line amount', $exception->getMessage());
        }

        $sharedMovement = $this->movement($this->repository, MovementDirection::In, '100.000');
        $firstLine = $this->line(MovementDirection::In, '70.000');
        $secondLine = $this->line(MovementDirection::In, '40.000');
        $service->allocate($firstLine->id, [['movementId' => $sharedMovement, 'amount' => '70.000']], $this->user->id);
        try {
            $service->allocate($secondLine->id, [['movementId' => $sharedMovement, 'amount' => '30.001']], $this->user->id);
            $this->fail('Movement overallocation must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('movement amount', $exception->getMessage());
        }

        $otherRepository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $foreignMovement = $this->movement($otherRepository, MovementDirection::In, '1.000');
        $foreignLine = $this->line(MovementDirection::In, '1.000');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('repository');
        $service->allocate($foreignLine->id, [['movementId' => $foreignMovement, 'amount' => '1.000']], $this->user->id);
    }

    public function test_ignore_unignore_and_conflicts_recompute_derived_status(): void
    {
        $service = app(StatementMatchingService::class);
        $line = $this->line(MovementDirection::Out, '20.000');

        $service->ignore($line->id, StatementLineIgnoreReason::Informational, 'Bank advice only', $this->user->id);
        $ignored = $line->fresh();
        $this->assertSame(StatementLineMatchStatus::Ignored, $ignored?->match_status);
        $this->assertSame(StatementLineIgnoreReason::Informational, $ignored?->ignore_reason);
        $this->assertSame('Bank advice only', $ignored?->ignore_text);

        $service->unignore($line->id, $this->user->id);
        $unignored = $line->fresh();
        $this->assertSame(StatementLineMatchStatus::Unmatched, $unignored?->match_status);
        $this->assertNull($unignored?->ignore_reason);
        $this->assertNull($unignored?->ignore_text);

        $movement = $this->movement($this->repository, MovementDirection::Out, '10.000');
        $service->allocate($line->id, [['movementId' => $movement, 'amount' => '10.000']], $this->user->id);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('allocations or executions');
        $service->ignore($line->id, StatementLineIgnoreReason::Other, 'Cannot hide work', $this->user->id);
    }

    public function test_unallocate_keeps_execution_and_exact_replay_reuses_it(): void
    {
        $line = $this->line(MovementDirection::In, '25.000');
        $movementId = $this->movement($this->repository, MovementDirection::In, '25.000');
        $handler = new class($movementId) implements StatementActionHandlerInterface
        {
            public int $calls = 0;

            public function __construct(private readonly string $movementId) {}

            public function supports(MatchActionType $action): bool
            {
                return $action === MatchActionType::InboundClear;
            }

            /** @param array<string, mixed> $params */
            public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
            {
                $this->calls++;

                return new ExecutionResult(
                    movementIds: [$this->movementId],
                    targetType: 'payment_instrument',
                    targetId: (string) $params['instrument_id'],
                    semanticDigest: StatementActionDigest::make(MatchActionType::InboundClear, $line, $params),
                );
            }
        };
        $this->app->instance(StatementActionRegistry::class, new StatementActionRegistry([$handler]));
        $service = app(StatementMatchingService::class);
        $params = ['instrument_id' => Str::uuid()->toString()];

        $service->executeAndAllocate($line->id, MatchActionType::InboundClear, $params, $this->user->id);
        $this->assertSame(1, $handler->calls);
        $this->assertDatabaseCount('bank_statement_match_executions', 1);
        $this->assertDatabaseCount('bank_statement_line_allocations', 1);

        $service->executeAndAllocate($line->id, MatchActionType::InboundClear, $params, $this->user->id);
        $this->assertSame(1, $handler->calls, 'An exact request retry must not execute the domain action twice.');
        $this->assertDatabaseCount('bank_statement_match_executions', 1);
        $this->assertDatabaseCount('bank_statement_line_allocations', 1);

        $service->unallocate($line->id, null, $this->user->id);
        $this->assertDatabaseCount('bank_statement_match_executions', 1);
        $this->assertDatabaseCount('bank_statement_line_allocations', 0);
        $this->assertSame(StatementLineMatchStatus::Unmatched, $line->fresh()?->match_status);

        $service->executeAndAllocate($line->id, MatchActionType::InboundClear, $params, $this->user->id);
        $this->assertSame(1, $handler->calls, 'Exact replay must reuse immutable execution provenance.');
        $this->assertDatabaseCount('bank_statement_match_executions', 1);
        $this->assertDatabaseCount('bank_statement_line_allocations', 1);
    }

    public function test_execution_digest_mismatch_and_unregistered_actions_fail_cleanly(): void
    {
        $line = $this->line(MovementDirection::In, '5.000');
        $movementId = $this->movement($this->repository, MovementDirection::In, '5.000');
        $handler = new class($movementId) implements StatementActionHandlerInterface
        {
            public function __construct(private readonly string $movementId) {}

            public function supports(MatchActionType $action): bool
            {
                return $action === MatchActionType::InboundClear;
            }

            /** @param array<string, mixed> $params */
            public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
            {
                return new ExecutionResult(
                    [$this->movementId],
                    'payment_instrument',
                    (string) $params['instrument_id'],
                    StatementActionDigest::make(MatchActionType::InboundClear, $line, $params),
                );
            }
        };
        $this->app->instance(StatementActionRegistry::class, new StatementActionRegistry([$handler]));
        $service = app(StatementMatchingService::class);
        $params = ['instrument_id' => Str::uuid()->toString(), 'fee_amount' => '0.000'];
        $service->executeAndAllocate($line->id, MatchActionType::InboundClear, $params, $this->user->id);
        $service->unallocate($line->id, null, $this->user->id);

        try {
            $service->executeAndAllocate(
                $line->id,
                MatchActionType::InboundClear,
                [...$params, 'fee_amount' => '1.000'],
                $this->user->id,
            );
            $this->fail('Changed semantic inputs must not reuse an execution.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('digest', $exception->getMessage());
        }
        $this->assertDatabaseCount('bank_statement_match_executions', 1);
        $this->assertDatabaseCount('bank_statement_line_allocations', 0);

        $otherLine = $this->line(MovementDirection::Out, '1.000');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not registered');
        $service->executeAndAllocate($otherLine->id, MatchActionType::AcquirerFee, [], $this->user->id);
    }

    public function test_reconciled_or_voided_statements_reject_every_mutation(): void
    {
        $service = app(StatementMatchingService::class);
        $reconciled = $this->line(MovementDirection::In, '1.000', BankStatementStatus::Reconciled);
        $voided = $this->line(MovementDirection::In, '1.000', BankStatementStatus::Voided);
        $movement = $this->movement($this->repository, MovementDirection::In, '1.000');

        foreach ([$reconciled, $voided] as $line) {
            try {
                $service->allocate($line->id, [['movementId' => $movement, 'amount' => '1.000']], $this->user->id);
                $this->fail('Terminal statement mutation must fail.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('cannot be changed', $exception->getMessage());
            }
        }
    }

    private function line(
        MovementDirection $direction,
        string $amount,
        BankStatementStatus $status = BankStatementStatus::Imported,
    ): BankStatementLine {
        $statement = BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => $status,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/matching-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'booking_date' => null,
            'direction' => $direction,
            'amount' => $amount,
            'reference' => null,
            'bank_transaction_id' => Str::uuid()->toString(),
            'label' => 'Matching test line',
            'counterparty_hint' => null,
            'match_status' => StatementLineMatchStatus::Unmatched,
            'ignore_reason' => null,
            'ignore_text' => null,
            'location_id' => $this->repository->location_id,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function movement(
        PaymentRepository $repository,
        MovementDirection $direction,
        string $amount,
    ): string {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $repository->tenant_id,
            'company_id' => $repository->company_id,
            'payment_repository_id' => $repository->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => $repository->currency,
            'balance_after' => $amount,
            'ordinal' => random_int(1, 1000000),
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'statement-matching:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $this->user->id,
        ]);

        return $id;
    }
}
