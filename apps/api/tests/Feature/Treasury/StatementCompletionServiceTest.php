<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\StatementCompletionService;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StatementCompletionServiceTest extends TestCase
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
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => 'Africa/Tunis',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
    }

    public function test_completion_revalidates_signed_lines_and_stamps_end_of_day_checkpoint(): void
    {
        $statement = $this->statement('2026-07-01', '2026-07-31', '100.000', '130.000');
        $incoming = $this->line($statement, 1, MovementDirection::In, '50.000');
        $outgoing = $this->line($statement, 2, MovementDirection::Out, '20.000');
        $this->allocate($incoming, $this->movement(MovementDirection::In, '50.000'), '50.000');
        $this->allocate($outgoing, $this->movement(MovementDirection::Out, '20.000'), '20.000');

        $completed = app(StatementCompletionService::class)->complete($statement->id, $this->user->id);

        $this->assertSame(BankStatementStatus::Reconciled, $completed->status);
        $this->repository->refresh();
        $expected = CarbonImmutable::parse('2026-07-31 23:59:59', 'Africa/Tunis')->utc();
        $this->assertTrue($this->repository->last_reconciled_at?->equalTo($expected));
        $this->assertSame('130.000', $this->repository->last_reconciled_balance);
    }

    public function test_completion_rejects_nonterminal_lines_and_signed_statement_mismatch(): void
    {
        $service = app(StatementCompletionService::class);
        $nonterminal = $this->statement('2026-07-01', '2026-07-15', '0.000', '10.000');
        $this->line($nonterminal, 1, MovementDirection::In, '10.000', StatementLineMatchStatus::Partial);

        try {
            $service->complete($nonterminal->id, $this->user->id);
            $this->fail('A statement with a partial line must not complete.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('terminal', $exception->getMessage());
        }
        $nonterminal->status = BankStatementStatus::Voided;
        $nonterminal->save();

        $mismatch = $this->statement('2026-07-16', '2026-07-31', '0.000', '9.000');
        $line = $this->line($mismatch, 1, MovementDirection::In, '10.000');
        $this->allocate($line, $this->movement(MovementDirection::In, '10.000'), '10.000');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('opening');
        $service->complete($mismatch->id, $this->user->id);
    }

    public function test_statements_complete_in_period_order_and_reopen_without_checkpoint_gaps(): void
    {
        $service = app(StatementCompletionService::class);
        $june = $this->statement('2026-06-01', '2026-06-30', '0.000', '0.000');
        $july = $this->statement('2026-07-01', '2026-07-31', '0.000', '0.000');

        try {
            $service->complete($july->id, $this->user->id);
            $this->fail('A later statement must wait for an earlier active statement.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('earlier', $exception->getMessage());
        }

        $service->complete($june->id, $this->user->id);
        $service->complete($july->id, $this->user->id);

        try {
            $service->reopen($june->id, $this->user->id);
            $this->fail('Reopening an older statement would leave a checkpoint gap.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('later', $exception->getMessage());
        }

        $reopened = $service->reopen($july->id, $this->user->id);
        $this->assertSame(BankStatementStatus::Reconciling, $reopened->status);
        $this->repository->refresh();
        $expected = CarbonImmutable::parse('2026-06-30 23:59:59', 'Africa/Tunis')->utc();
        $this->assertTrue($this->repository->last_reconciled_at?->equalTo($expected));
        $this->assertSame('0.000', $this->repository->last_reconciled_balance);
    }

    private function statement(
        string $start,
        string $end,
        string $opening,
        string $closing,
        BankStatementStatus $status = BankStatementStatus::Reconciling,
    ): BankStatement {
        return BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => $start,
            'period_end' => $end,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'status' => $status,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/completion-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->user->id,
            'imported_at' => now(),
        ]);
    }

    private function line(
        BankStatement $statement,
        int $number,
        MovementDirection $direction,
        string $amount,
        StatementLineMatchStatus $status = StatementLineMatchStatus::Matched,
    ): BankStatementLine {
        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => $number,
            'value_date' => $statement->period_end,
            'booking_date' => null,
            'direction' => $direction,
            'amount' => $amount,
            'reference' => null,
            'bank_transaction_id' => Str::uuid()->toString(),
            'label' => 'Completion test line',
            'counterparty_hint' => null,
            'match_status' => $status,
            'ignore_reason' => null,
            'ignore_text' => null,
            'location_id' => $this->repository->location_id,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function movement(MovementDirection $direction, string $amount): string
    {
        $id = Str::uuid()->toString();
        DB::table('repository_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => 'TND',
            'balance_after' => $amount,
            'ordinal' => random_int(1, 1000000),
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'completion:'.Str::uuid()->toString(),
            'occurred_at' => now(),
        ]);

        return $id;
    }

    private function allocate(BankStatementLine $line, string $movementId, string $amount): void
    {
        DB::table('bank_statement_line_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => $amount,
            'match_type' => 'manual',
            'matched_by' => $this->user->id,
            'matched_at' => now(),
        ]);
    }
}
