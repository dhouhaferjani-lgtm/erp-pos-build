<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Events\BankStatementReconciled;
use App\Modules\Treasury\Domain\Events\BankStatementReopened;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StatementCompletionHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $accountant;

    private User $admin;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = $this->user('accountant');
        $this->admin = $this->user('admin');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->repository = PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'bank_account',
            'currency' => 'TND',
        ]);
    }

    public function test_accountant_completes_but_only_admin_reopens_and_events_are_dispatched(): void
    {
        Event::fake([BankStatementReconciled::class, BankStatementReopened::class]);
        $statement = $this->statement();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$statement->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');
        Event::assertDispatched(BankStatementReconciled::class);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$statement->id}/reopen")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bank-statements/{$statement->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciling');
        Event::assertDispatched(BankStatementReopened::class);
    }

    public function test_nonzero_ignored_total_requires_admin_acknowledgment(): void
    {
        $statement = $this->statement();
        $statement->update(['closing_balance' => '10.000']);
        BankStatementLine::query()->create([
            'bank_statement_id' => $statement->id,
            'payment_repository_id' => $this->repository->id,
            'line_number' => 1,
            'value_date' => '2026-07-31',
            'direction' => MovementDirection::In,
            'amount' => '10.000',
            'label' => 'Ignored bank advice',
            'match_status' => StatementLineMatchStatus::Ignored,
            'ignore_reason' => StatementLineIgnoreReason::Informational,
            'ignore_text' => 'No treasury movement exists',
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'dedupe_active' => true,
        ]);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/bank-statements/{$statement->id}/complete", [
                'acknowledge_ignored_total' => true,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bank-statements/{$statement->id}/complete")
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bank-statements/{$statement->id}/complete", [
                'acknowledge_ignored_total' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        $audit = AuditEvent::query()
            ->where('event_type', 'treasury.bank_statement.reconciled')
            ->where('aggregate_id', $statement->id)
            ->sole();
        $this->assertSame('10.000', $audit->payload['signed_ignored_total']);
        $this->assertTrue($audit->payload['ignored_total_acknowledged']);
    }

    public function test_completion_and_reopen_are_persisted_to_the_audit_log(): void
    {
        $statement = $this->statement();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bank-statements/{$statement->id}/complete")
            ->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bank-statements/{$statement->id}/reopen")
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'event_type' => 'treasury.bank_statement.reconciled',
            'aggregate_type' => 'BankStatement',
            'aggregate_id' => $statement->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'event_type' => 'treasury.bank_statement.reopened',
            'aggregate_type' => 'BankStatement',
            'aggregate_id' => $statement->id,
        ]);
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

    private function statement(): BankStatement
    {
        return BankStatement::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'payment_repository_id' => $this->repository->id,
            'currency' => 'TND',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => BankStatementStatus::Reconciling,
            'source_file_sha256' => hash('sha256', Str::uuid()->toString()),
            'source_file_path' => 'bank-statements/http-completion-'.Str::uuid()->toString().'.csv',
            'parser_profile_id' => null,
            'imported_by' => $this->accountant->id,
            'imported_at' => now(),
        ]);
    }
}
