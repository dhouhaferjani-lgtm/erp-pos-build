<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementCompletionService;
use App\Modules\Treasury\Application\Services\StatementMatchingService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/** PostgreSQL-only proofs for the completion/matching lock order. */
final class StatementCompletionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    public function test_matching_and_completion_serialize_without_torn_statement_state(): void
    {
        $this->requireConcurrencyRuntime();
        [$company, $user, $repository] = $this->context();
        $movementId = $this->movement($company, $user, $repository, '100.000');
        $statement = $this->statement($company, $user, $repository, '100.000');
        $line = $this->line($statement, $repository, '100.000', StatementLineMatchStatus::Unmatched);

        [$release, $wait] = $this->forkAttempt(fn () => app(StatementMatchingService::class)->allocate(
            $line->id,
            [['movementId' => $movementId, 'amount' => '100.000']],
            $user->id,
        ));
        $release();
        $parentSucceeded = $this->attempt(fn () => app(StatementCompletionService::class)
            ->complete($statement->id, $user->id));
        $childSucceeded = $wait();

        $this->assertTrue($childSucceeded);
        $this->assertSame(StatementLineMatchStatus::Matched, $line->fresh()?->match_status);
        $this->assertSame(
            $parentSucceeded ? BankStatementStatus::Reconciled : BankStatementStatus::Reconciling,
            $statement->fresh()?->status,
        );
        $this->assertSame(1, DB::table('bank_statement_line_allocations')
            ->where('bank_statement_line_id', $line->id)->count());
    }

    public function test_completion_serializes_against_another_statement_allocating_the_same_movement(): void
    {
        $this->requireConcurrencyRuntime();
        [$company, $user, $repository] = $this->context();
        $movementId = $this->movement($company, $user, $repository, '100.000');
        $first = $this->statement($company, $user, $repository, '100.000');
        $firstLine = $this->line($first, $repository, '100.000', StatementLineMatchStatus::Matched);
        $this->allocation($firstLine, $movementId, $user, '100.000');
        $second = $this->statement($company, $user, $repository, '0.000', '2026-08-01', '2026-08-31');
        $secondLine = $this->line($second, $repository, '1.000', StatementLineMatchStatus::Unmatched);

        [$release, $wait] = $this->forkAttempt(fn () => app(StatementMatchingService::class)->allocate(
            $secondLine->id,
            [['movementId' => $movementId, 'amount' => '1.000']],
            $user->id,
        ));
        $release();
        $completionSucceeded = $this->attempt(fn () => app(StatementCompletionService::class)
            ->complete($first->id, $user->id));
        $allocationSucceeded = $wait();

        $this->assertTrue($completionSucceeded);
        $this->assertFalse($allocationSucceeded);
        $this->assertSame(BankStatementStatus::Reconciled, $first->fresh()?->status);
        $this->assertSame(0, DB::table('bank_statement_line_allocations')
            ->where('bank_statement_line_id', $secondLine->id)->count());
        $this->assertSame('100.000', (string) DB::table('bank_statement_line_allocations')
            ->where('repository_movement_id', $movementId)->sum('matched_amount'));
    }

    private function requireConcurrencyRuntime(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-connection completion contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process completion proof.');
        }
    }

    /**
     * Start an attempt in a child and return separate release/wait callbacks.
     *
     * @param  callable(): mixed  $operation
     * @return array{callable(): void, callable(): bool}
     */
    private function forkAttempt(callable $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'statement-completion-');
        self::assertIsString($resultFile);
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();
            fread($childSocket, 1);
            fclose($childSocket);
            file_put_contents($resultFile, json_encode([
                'succeeded' => $this->attempt($operation),
            ], JSON_THROW_ON_ERROR));
            exit(0);
        }
        fclose($childSocket);

        $release = function () use ($parentSocket): void {
            fwrite($parentSocket, '1');
        };
        $wait = function () use ($parentSocket, $pid, $resultFile): bool {
            try {
                fclose($parentSocket);
                $status = 0;
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
                $payload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);

                return ($payload['succeeded'] ?? null) === true;
            } finally {
                if (is_resource($parentSocket)) {
                    fclose($parentSocket);
                }
                if (is_file($resultFile)) {
                    unlink($resultFile);
                }
            }
        };

        return [$release, $wait];
    }

    /** @param callable(): mixed $operation */
    private function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{Company, User, PaymentRepository} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);

        return [$company, $user, $repository];
    }

    private function movement(Company $company, User $user, PaymentRepository $repository, string $amount): string
    {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'direction' => MovementDirection::In->value,
            'amount' => $amount,
            'currency' => 'TND',
            'balance_after' => $amount,
            'ordinal' => 1,
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'completion-race:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $user->id,
        ]);

        return $id;
    }

    private function statement(
        Company $company,
        User $user,
        PaymentRepository $repository,
        string $closing,
        string $start = '2026-07-01',
        string $end = '2026-07-31',
    ): BankStatement {
        return BankStatement::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'currency' => 'TND',
            'period_start' => $start,
            'period_end' => $end,
            'opening_balance' => '0.000',
            'closing_balance' => $closing,
            'status' => BankStatementStatus::Reconciling,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/completion-race-'.Str::uuid()->toString().'.csv',
            'imported_by' => $user->id,
            'imported_at' => now(),
        ]);
    }

    private function line(
        BankStatement $statement,
        PaymentRepository $repository,
        string $amount,
        StatementLineMatchStatus $status,
    ): BankStatementLine {
        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $repository->id,
            'line_number' => 1,
            'value_date' => $statement->period_end,
            'direction' => MovementDirection::In,
            'amount' => $amount,
            'label' => 'Completion race line',
            'match_status' => $status,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function allocation(BankStatementLine $line, string $movementId, User $user, string $amount): void
    {
        DB::table('bank_statement_line_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => $amount,
            'match_type' => 'manual',
            'matched_by' => $user->id,
            'matched_at' => now(),
        ]);
    }
}
