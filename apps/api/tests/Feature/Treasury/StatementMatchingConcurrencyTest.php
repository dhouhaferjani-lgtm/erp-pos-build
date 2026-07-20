<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
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

/** PostgreSQL-only proof that empty allocation sets cannot race past the movement cap. */
final class StatementMatchingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    public function test_two_first_allocations_on_one_movement_have_exactly_one_winner(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-connection matching contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-process matching proof.');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $repository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $movementId = $this->movement($user, $company, $repository);
        $parentLine = $this->line($user, $company, $repository, 1);
        $childLine = $this->line($user, $company, $repository, 2);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'statement-allocation-');
        self::assertIsString($resultFile);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();
            fread($childSocket, 1);
            fclose($childSocket);
            $this->attemptAllocation($childLine->id, $movementId, $user->id, $resultFile);
            exit(0);
        }

        fclose($childSocket);
        try {
            fwrite($parentSocket, '1');
            fclose($parentSocket);
            $parentSucceeded = true;
            try {
                app(StatementMatchingService::class)->allocate(
                    $parentLine->id,
                    [['movementId' => $movementId, 'amount' => '60.000']],
                    $user->id,
                );
            } catch (Throwable) {
                $parentSucceeded = false;
            }

            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $childPayload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($childPayload);
            $childSucceeded = ($childPayload['succeeded'] ?? null) === true;
            self::assertCount(1, array_filter(
                [$parentSucceeded, $childSucceeded],
                static fn (bool $succeeded): bool => $succeeded,
            ));
            self::assertSame('60.000', (string) DB::table('bank_statement_line_allocations')
                ->where('repository_movement_id', $movementId)
                ->sum('matched_amount'));
            self::assertSame(1, DB::table('bank_statement_line_allocations')
                ->where('repository_movement_id', $movementId)
                ->count());
        } finally {
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    private function attemptAllocation(string $lineId, string $movementId, string $userId, string $resultFile): void
    {
        try {
            app(StatementMatchingService::class)->allocate(
                $lineId,
                [['movementId' => $movementId, 'amount' => '60.000']],
                $userId,
            );
            $payload = ['succeeded' => true];
        } catch (Throwable $exception) {
            $payload = [
                'succeeded' => false,
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }
        file_put_contents($resultFile, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function movement(User $user, Company $company, PaymentRepository $repository): string
    {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'direction' => MovementDirection::In->value,
            'amount' => '100.000',
            'currency' => 'TND',
            'balance_after' => '100.000',
            'ordinal' => 1,
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'statement-race:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $user->id,
        ]);

        return $id;
    }

    private function line(User $user, Company $company, PaymentRepository $repository, int $number): BankStatementLine
    {
        $statement = BankStatement::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', "statement-race-{$number}"),
            'source_file_path' => "bank-statements/race-{$number}.csv",
            'parser_profile_id' => null,
            'imported_by' => $user->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'direction' => MovementDirection::In,
            'amount' => '60.000',
            'label' => "Race line {$number}",
            'match_status' => StatementLineMatchStatus::Unmatched,
            'location_id' => $repository->location_id,
            'fingerprint' => hash('sha256', "statement-race-line-{$number}"),
            'dedupe_active' => true,
        ]);
    }
}
