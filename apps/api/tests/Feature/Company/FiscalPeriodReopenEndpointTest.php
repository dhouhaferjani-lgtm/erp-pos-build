<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Session B lane Q-10 (c): permissioned `Closed -> Open` reopen path for fiscal periods.
 *
 * Evidence: the tenancy + doc-adjacent state-machine sub-report of 2026-08-23,
 * "[HIGH] Nightly fiscal-period auto-lock: one-way mass transition, Tunisia's threshold
 * applied to every country, no audit, no reopen" — `PeriodStatus::Open` was written in
 * exactly ONE place in the codebase (FiscalYearCreationService, at fiscal-year creation),
 * so Closed and Locked were terminal with zero in-product outgoing edges and the only
 * operator recovery was a manual `UPDATE fiscal_periods`.
 *
 * The endpoint mirrors `VatPeriodManagementService::reopenPeriod` /
 * `VatPeriodController::reopen` exactly: transactional, refuses unless the period is
 * Closed, refuses when a successor period is already Closed or Locked (the ordering
 * invariant), 422 on the DomainException, and undo-semantics on the audit stamps
 * (`closed_at`/`closed_by` nulled, `reopened_at`/`reopened_by`/`reopen_reason` written).
 *
 * Locked stays TERMINAL in this lane by design — the full PeriodStatus adjacency map is
 * program scope.
 */
final class FiscalPeriodReopenEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Company creation fires CreateFiscalYearsForNewCompany, which seeds past/current
        // fiscal years whose elapsed periods are already Closed (FiscalYearCreationService
        // :123-142). Those would be legitimate Closed SUCCESSORS of the fixture period and
        // would make every case here refuse for the wrong reason — so the auto-created
        // years are dropped and each test builds exactly the periods it means to assert on.
        // (`fiscal_periods.fiscal_year_id` is ON DELETE CASCADE.)
        $this->company->fiscalYears()->delete();

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2026',
            'start_date' => Carbon::parse('2026-01-01'),
            'end_date' => Carbon::parse('2026-12-31'),
            'is_closed' => false,
        ]);
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $company ??= $this->company;

        $user = User::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function period(int $number, PeriodStatus $status, ?FiscalYear $year = null, ?Company $company = null): FiscalPeriod
    {
        $year ??= $this->fiscalYear;
        $company ??= $this->company;

        $start = Carbon::parse('2026-01-01')->addMonths($number - 1)->startOfMonth();

        return FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $company->id,
            'name' => $start->format('F Y'),
            'period_number' => $number,
            'start_date' => $start,
            'end_date' => $start->copy()->endOfMonth(),
            'status' => $status,
            'closed_at' => $status === PeriodStatus::Open ? null : Carbon::parse('2026-05-01 03:00:00'),
            'status_actor' => $status === PeriodStatus::Open ? null : 'system:auto-lock',
            'status_changed_from' => $status === PeriodStatus::Open ? null : PeriodStatus::Open->value,
        ]);
    }

    // ── Happy path ─────────────────────────────────────────────────────

    public function test_accountant_can_reopen_a_closed_period_with_no_closed_successor(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", [
                'reason' => 'Supplier invoice arrived after the nightly auto-lock.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', PeriodStatus::Open->value);

        $period->refresh();
        $this->assertSame(PeriodStatus::Open, $period->status);
        $this->assertNull($period->closed_at, 'undo semantics: the close stamp is cleared');
        $this->assertNull($period->closed_by);
        $this->assertNotNull($period->reopened_at);
        $this->assertSame($accountant->id, $period->reopened_by);
        $this->assertSame('Supplier invoice arrived after the nightly auto-lock.', $period->reopen_reason);
        $this->assertSame('user:'.$accountant->id, $period->status_actor);
        $this->assertSame(PeriodStatus::Closed->value, $period->status_changed_from);
    }

    // ── Guards ─────────────────────────────────────────────────────────

    public function test_it_refuses_when_a_successor_period_is_closed(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);
        $this->period(4, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertStatus(422);
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_it_refuses_when_a_successor_period_is_locked(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);
        $this->period(5, PeriodStatus::Locked);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertStatus(422);
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_it_refuses_to_reopen_a_locked_period(): void
    {
        $period = $this->period(3, PeriodStatus::Locked);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertStatus(422);
        $this->assertSame(PeriodStatus::Locked, $period->refresh()->status);
    }

    public function test_it_refuses_to_reopen_an_already_open_period(): void
    {
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertStatus(422);
    }

    public function test_it_refuses_when_the_fiscal_year_is_closed(): void
    {
        $closedYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-12-31'),
            'is_closed' => true,
        ]);

        $period = $this->period(3, PeriodStatus::Closed, $closedYear);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertStatus(422);
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    // ── Authorization ──────────────────────────────────────────────────

    public function test_viewer_cannot_reopen_a_period(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('viewer'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertForbidden();
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_manager_cannot_reopen_a_period(): void
    {
        // Mirrors the 2026-08-06 gate finding I-1 ruling that dropped
        // `reports.manage` (VAT period generate/close/reopen/file) from manager:
        // period-lifecycle reversal is financial-lifecycle mutation.
        $period = $this->period(3, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('manager'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertForbidden();
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_admin_can_reopen_a_period(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertOk();
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    // ── Validation + company scoping ───────────────────────────────────

    public function test_reason_is_required(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.reason.0', fn (?string $m): bool => $m !== null);
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_a_period_of_another_company_is_not_reachable(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);
        $otherCompany->fiscalYears()->delete();
        $otherYear = FiscalYear::create([
            'company_id' => $otherCompany->id,
            'name' => '2026',
            'start_date' => Carbon::parse('2026-01-01'),
            'end_date' => Carbon::parse('2026-12-31'),
            'is_closed' => false,
        ]);
        $foreignPeriod = $this->period(3, PeriodStatus::Closed, $otherYear, $otherCompany);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$foreignPeriod->id}/reopen", ['reason' => 'Correction needed.']);

        $response->assertNotFound();
        $this->assertSame(PeriodStatus::Closed, $foreignPeriod->refresh()->status);
    }
}
