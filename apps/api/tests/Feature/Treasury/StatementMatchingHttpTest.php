<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StatementMatchingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $accountant;

    private User $manager;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = $this->user('accountant');
        $this->manager = $this->user('manager');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
    }

    public function test_accountant_can_allocate_unallocate_ignore_and_unignore(): void
    {
        $line = $this->line('100.000');
        $movementId = $this->movement($this->company, $this->repository, '100.000');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/allocations", [
                'allocations' => [[
                    'repository_movement_id' => $movementId,
                    'amount' => '100.000',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.allocations.0.repository_movement_id', $movementId);

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/bank-statement-lines/{$line->id}/allocations/{$movementId}")
            ->assertOk()
            ->assertJsonPath('data.match_status', 'unmatched')
            ->assertJsonCount(0, 'data.allocations');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/ignore", [
                'reason' => 'informational',
                'text' => 'Bank advice only',
            ])
            ->assertOk()
            ->assertJsonPath('data.match_status', 'ignored');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/bank-statement-lines/{$line->id}/ignore")
            ->assertOk()
            ->assertJsonPath('data.match_status', 'unmatched');
    }

    public function test_routes_enforce_permission_line_scope_and_movement_scope(): void
    {
        $line = $this->line('1.000');
        $movementId = $this->movement($this->company, $this->repository, '1.000');
        $payload = ['allocations' => [[
            'repository_movement_id' => $movementId,
            'amount' => '1.000',
        ]]];

        $this->actingAs($this->manager)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/allocations", $payload)
            ->assertForbidden();

        $otherCompany = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        $otherRepository = PaymentRepository::factory()->for($otherCompany)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
        $otherMovement = $this->movement($otherCompany, $otherRepository, '1.000');
        $validation = $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/allocations", [
                'allocations' => [[
                    'repository_movement_id' => $otherMovement,
                    'amount' => '1.000',
                ]],
            ])
            ->assertUnprocessable();
        $errors = $validation->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('allocations.0.repository_movement_id', $errors);

        UserCompanyMembership::query()->create([
            'user_id' => $this->accountant->id,
            'company_id' => $otherCompany->id,
            'role' => 'accountant',
        ]);
        $this->actingAs($this->accountant)
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/allocations", [
                'allocations' => [[
                    'repository_movement_id' => $otherMovement,
                    'amount' => '1.000',
                ]],
            ])
            ->assertNotFound();
    }

    public function test_action_endpoint_rejects_missing_acquirer_parameters_without_financial_writes(): void
    {
        $line = $this->line('1.000');

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statement-lines/{$line->id}/actions", [
                'action' => 'acquirer_fee',
                'params' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertDatabaseCount('bank_statement_match_executions', 0);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'acquirer_fee']);
    }

    public function test_suggestion_endpoint_returns_ranked_read_only_candidates(): void
    {
        $line = $this->line('25.000');
        $movementId = $this->movement($this->company, $this->repository, '25.000');

        $this->actingAs($this->accountant)
            ->getJson("/api/v1/bank-statement-lines/{$line->id}/suggestions")
            ->assertOk()
            ->assertJsonPath('data.0.tier', 2)
            ->assertJsonPath('data.0.movement_ids.0', $movementId)
            ->assertJsonPath('data.0.amount', '25.000');
        $this->assertDatabaseCount('bank_statement_line_allocations', 0);

        $this->actingAs($this->manager)
            ->getJson("/api/v1/bank-statement-lines/{$line->id}/suggestions")
            ->assertForbidden();
    }

    public function test_statement_detail_exposes_matching_and_execution_provenance(): void
    {
        $line = $this->line('25.000');
        $movementId = $this->movement($this->company, $this->repository, '25.000');
        $targetId = Str::uuid()->toString();

        DB::table('bank_statement_line_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'bank_statement_line_id' => $line->id,
            'repository_movement_id' => $movementId,
            'matched_amount' => '10.000',
            'match_type' => 'manual',
            'matched_by' => $this->accountant->id,
            'matched_at' => now(),
        ]);
        DB::table('bank_statement_match_executions')->insert([
            'id' => Str::uuid()->toString(),
            'bank_statement_line_id' => $line->id,
            'action_type' => 'outbound_clear',
            'action_key' => "stmtline:{$line->id}:outbound_clear",
            'semantic_digest' => hash('sha256', 'statement-detail-provenance'),
            'target_type' => 'payment_instrument',
            'target_id' => $targetId,
            'produced_repository_movement_ids' => json_encode([$movementId], JSON_THROW_ON_ERROR),
            'executed_by' => $this->accountant->id,
            'executed_at' => now(),
        ]);
        $line->update([
            'match_status' => StatementLineMatchStatus::Partial,
            'ignore_reason' => null,
            'ignore_text' => null,
        ]);

        $this->actingAs($this->accountant)
            ->getJson("/api/v1/bank-statements/{$line->bank_statement_id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.ignore_reason', null)
            ->assertJsonPath('data.lines.0.ignore_text', null)
            ->assertJsonPath('data.lines.0.allocations.0.repository_movement_id', $movementId)
            ->assertJsonPath('data.lines.0.allocations.0.matched_amount', '10.000')
            ->assertJsonPath('data.lines.0.allocations.0.movement_direction', 'in')
            ->assertJsonPath('data.lines.0.executions.0.action_type', 'outbound_clear')
            ->assertJsonPath('data.lines.0.executions.0.target_type', 'payment_instrument')
            ->assertJsonPath('data.lines.0.executions.0.target_id', $targetId)
            ->assertJsonPath('data.lines.0.executions.0.produced_repository_movement_ids.0', $movementId);

        $this->actingAs($this->accountant)
            ->getJson("/api/v1/bank-statement-targets/payment_instrument/{$targetId}/lines")
            ->assertOk()
            ->assertJsonPath('data.0.bank_statement_id', $line->bank_statement_id)
            ->assertJsonPath('data.0.bank_statement_line_id', $line->id)
            ->assertJsonPath('data.0.action_type', 'outbound_clear');

        $this->actingAs($this->manager)
            ->getJson("/api/v1/bank-statement-targets/payment_instrument/{$targetId}/lines")
            ->assertForbidden();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function line(string $amount): BankStatementLine
    {
        $statement = BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Imported,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/http-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->accountant->id,
            'imported_at' => now(),
        ]);

        return BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-18',
            'direction' => MovementDirection::In,
            'amount' => $amount,
            'label' => 'HTTP matching test',
            'match_status' => StatementLineMatchStatus::Unmatched,
            'location_id' => $this->repository->location_id,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);
    }

    private function movement(Company $company, PaymentRepository $repository, string $amount): string
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
            'ordinal' => random_int(1, 1000000),
            'source_type' => 'adjustment',
            'source_id' => Str::uuid()->toString(),
            'idempotency_key' => 'statement-http:'.Str::uuid()->toString(),
            'occurred_at' => now(),
            'created_by' => $this->accountant->id,
        ]);

        return $id;
    }
}
