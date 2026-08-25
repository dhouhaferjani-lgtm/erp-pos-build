<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\FiscalPeriodCloseRefusalCode;
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
 * Session B2 lane C-24 (i): permissioned `Open -> Closed` manual close for a fiscal period.
 *
 * WHY THIS ENDPOINT EXISTS
 * ------------------------
 * Lane Q-10 (`f5cae1f12`) made the nightly {@see FiscalPeriodAutoLockService} SKIP any
 * period a human reopened (`reopened_at IS NOT NULL`) and HOLD the close of the fiscal
 * YEAR containing it. Both docblocks state the honest consequence: with no manual close,
 * a reopened period — and its whole fiscal year — stays Open forever.
 *
 * `lockPeriodsInClosedFiscalYears()` already anticipates "reopened but Closed again by a
 * human" (it excludes only `Open AND reopened_at IS NOT NULL`), so the `Open -> Closed`
 * write is the exact missing edge. The integration pin at the bottom of this class is the
 * one that matters: reopen -> manual close -> the nightly service closes the year and
 * locks the period, with no change to the scheduler at all.
 *
 * FIXTURE NOTE: the base fiscal year is LAST calendar year, so every period built on it
 * has already ended (the `NOT_ENDED` guard) whatever day of the year this suite runs.
 */
final class FiscalPeriodCloseEndpointTest extends TestCase
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
        // fiscal years whose elapsed periods are already Closed. Those would be legitimate
        // predecessors/successors of the fixture period and would make cases here refuse
        // for the wrong reason — so the auto-created years are dropped and each test builds
        // exactly the periods it means to assert on. (`fiscal_year_id` is ON DELETE CASCADE.)
        $this->company->fiscalYears()->delete();

        $this->fiscalYear = $this->year($this->company, Carbon::today()->subYear()->startOfYear());
    }

    private function year(Company $company, Carbon $start, bool $isClosed = false): FiscalYear
    {
        return FiscalYear::create([
            'company_id' => $company->id,
            'name' => $start->format('Y'),
            'start_date' => $start->copy(),
            'end_date' => $start->copy()->endOfYear(),
            'is_closed' => $isClosed,
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

    /**
     * Build period `$number` of `$year` (month-sized), in `$status`.
     */
    private function period(int $number, PeriodStatus $status, ?FiscalYear $year = null, ?Company $company = null): FiscalPeriod
    {
        $year ??= $this->fiscalYear;
        $company ??= $this->company;

        $start = $year->start_date->copy()->addMonths($number - 1)->startOfMonth();

        return FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $company->id,
            'name' => $start->format('F Y'),
            'period_number' => $number,
            'start_date' => $start,
            'end_date' => $start->copy()->endOfMonth(),
            'status' => $status,
            'closed_at' => $status === PeriodStatus::Open ? null : $start->copy()->addMonth(),
            'status_actor' => $status === PeriodStatus::Open ? null : 'system:auto-lock',
            'status_changed_from' => $status === PeriodStatus::Open ? null : PeriodStatus::Open->value,
        ]);
    }

    // ── Happy path ─────────────────────────────────────────────────────

    public function test_accountant_can_close_an_ended_open_period(): void
    {
        $period = $this->period(3, PeriodStatus::Open);
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", [
                'reason' => 'Correction settled; closing the period again.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', PeriodStatus::Closed->value);

        $period->refresh();
        $this->assertSame(PeriodStatus::Closed, $period->status);
        $this->assertNotNull($period->closed_at);
        $this->assertSame($accountant->id, $period->closed_by);
        $this->assertSame('user:'.$accountant->id, $period->status_actor);
        $this->assertSame(PeriodStatus::Open->value, $period->status_changed_from);
        $this->assertNull($period->locked_at, 'a manual close never locks');
    }

    public function test_closing_keeps_the_reopen_history_on_the_row(): void
    {
        // LEDGER C-24 wording: "stamps closed_by, clears nothing". The scheduler's
        // STEP 3 predicate (`Open AND reopened_at IS NOT NULL`) depends on
        // `reopened_at` surviving the manual close.
        $period = $this->period(3, PeriodStatus::Open);
        $reopener = $this->userWithRole('accountant');
        $reopenedAt = Carbon::now()->subDay();
        $period->forceFill([
            'reopened_at' => $reopenedAt,
            'reopened_by' => $reopener->id,
            'reopen_reason' => 'Supplier invoice arrived late.',
        ])->save();

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertOk();

        $period->refresh();
        $this->assertSame(PeriodStatus::Closed, $period->status);
        $this->assertNotNull($period->reopened_at, 'the reopen stamp is history, not state to clear');
        $this->assertSame($reopener->id, $period->reopened_by);
        $this->assertSame('Supplier invoice arrived late.', $period->reopen_reason);
    }

    // ── Guards ─────────────────────────────────────────────────────────

    public function test_it_refuses_to_close_a_locked_period(): void
    {
        $period = $this->period(3, PeriodStatus::Locked);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::PeriodLocked->value);
        $this->assertSame(PeriodStatus::Locked, $period->refresh()->status);
    }

    public function test_it_refuses_to_close_an_already_closed_period(): void
    {
        $period = $this->period(3, PeriodStatus::Closed);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::PeriodNotOpen->value);
    }

    public function test_it_refuses_when_the_fiscal_year_is_closed(): void
    {
        $closedYear = $this->year($this->company, Carbon::today()->subYears(2)->startOfYear(), true);
        $period = $this->period(3, PeriodStatus::Open, $closedYear);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::FiscalYearClosed->value);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_it_refuses_when_an_earlier_period_is_still_open(): void
    {
        // The mirror of reopen's `successorSettled`: periods settle in sequence, and
        // the scheduler's oldest-first assumption drifts if a later period is closed
        // ahead of an earlier one that is still Open.
        $period = $this->period(3, PeriodStatus::Open);
        $this->period(2, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::PredecessorOpen->value);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_a_closed_predecessor_does_not_block_the_close(): void
    {
        $period = $this->period(3, PeriodStatus::Open);
        $this->period(2, PeriodStatus::Closed);
        $this->period(1, PeriodStatus::Locked);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertOk();
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_it_refuses_to_close_a_period_that_has_not_ended(): void
    {
        // JUDGEMENT CALL (lane brief, flagged for the reviewer): a period is closed
        // AFTER it ends. A period whose `end_date` is today or later still accepts
        // postings by definition.
        $currentYear = $this->year($this->company, Carbon::today()->startOfYear());
        $period = $this->period((int) Carbon::today()->format('n'), PeriodStatus::Open, $currentYear);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::PeriodNotEnded->value);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_a_period_that_has_not_ended_refuses_on_not_ended_even_behind_an_open_predecessor(): void
    {
        // PRECEDENCE PIN — parent ruling on treasury gate r1, finding C-1.
        // BOTH refusal conditions are true here: the period has not ended AND an earlier
        // period of the same company is still Open. The contract says the caller is told
        // NOT_ENDED, because a period that has not ended is never closable whatever its
        // neighbours look like — reporting PREDECESSOR_OPEN would send the operator to go
        // close December first, which cannot make this period closable.
        $currentYear = $this->year($this->company, Carbon::today()->startOfYear());
        $period = $this->period((int) Carbon::today()->format('n'), PeriodStatus::Open, $currentYear);

        // The predecessor: the last period of the PREVIOUS fiscal year, still Open. Taken
        // from last year rather than from this one so the fixture holds in January too.
        $predecessor = $this->period(12, PeriodStatus::Open);

        // Both preconditions asserted explicitly — a precedence test proves nothing if one
        // of the two conditions silently stopped holding.
        $this->assertTrue(
            $period->end_date->gte(Carbon::today()),
            'precondition: the period has NOT ended'
        );
        $this->assertTrue(
            $predecessor->start_date->lt($period->start_date) && $predecessor->status === PeriodStatus::Open,
            'precondition: an EARLIER period of the same company is still Open'
        );

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', FiscalPeriodCloseRefusalCode::PeriodNotEnded->value);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    // ── Authorization ──────────────────────────────────────────────────

    public function test_viewer_cannot_close_a_period(): void
    {
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('viewer'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertForbidden();
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_manager_cannot_close_a_period(): void
    {
        // Same role set as `fiscal-periods.reopen`: period-lifecycle mutation is
        // financial-lifecycle, not day-to-day operations (2026-08-06 gate I-1).
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('manager'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertForbidden();
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_admin_can_close_a_period(): void
    {
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertOk();
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    // ── Validation + company scoping ───────────────────────────────────

    public function test_reason_is_optional_and_not_persisted(): void
    {
        // There is no `close_reason` column and this lane ships no migration: the
        // reason is accepted and logged, never stored. Recorded as a lane residual.
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", []);

        $response->assertOk();
        $this->assertSame(PeriodStatus::Closed, $period->refresh()->status);
    }

    public function test_a_too_short_reason_is_rejected(): void
    {
        $period = $this->period(3, PeriodStatus::Open);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", ['reason' => 'x']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.reason.0', fn (?string $m): bool => $m !== null);
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
    }

    public function test_a_period_of_another_company_is_not_reachable(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);
        $otherCompany->fiscalYears()->delete();
        $otherYear = $this->year($otherCompany, Carbon::today()->subYear()->startOfYear());
        $foreignPeriod = $this->period(3, PeriodStatus::Open, $otherYear, $otherCompany);

        $response = $this->actingAs($this->userWithRole('accountant'))
            ->postJson("/api/v1/fiscal-periods/{$foreignPeriod->id}/close", []);

        $response->assertNotFound();
        $this->assertSame(PeriodStatus::Open, $foreignPeriod->refresh()->status);
    }

    // ── THE INTEGRATION PIN (lane C-24 (i) reason for existing) ─────────

    public function test_reopen_then_manual_close_releases_the_scheduler_hold_on_the_fiscal_year(): void
    {
        // A fiscal year that has ENDED, with three settled periods. The last one is the
        // only reopenable one (reopen refuses behind a Closed/Locked successor).
        $this->period(1, PeriodStatus::Closed);
        $this->period(2, PeriodStatus::Closed);
        $period = $this->period(3, PeriodStatus::Closed);

        $accountant = $this->userWithRole('accountant');

        // 1. Reopen it (lane Q-10's edge).
        $this->actingAs($accountant)
            ->postJson("/api/v1/fiscal-periods/{$period->id}/reopen", [
                'reason' => 'Supplier invoice arrived after the nightly auto-lock.',
            ])->assertOk();

        // 2. The nightly service now HOLDS everything: the period is skipped and its
        //    fiscal year is not closed. This is the "stays Open forever" state.
        app(FiscalPeriodAutoLockService::class)->lockExpiredPeriods();
        $this->assertSame(PeriodStatus::Open, $period->refresh()->status);
        $this->assertFalse($this->fiscalYear->refresh()->is_closed, 'the year close is held while a reopened period is Open');

        // 3. The human settles the correction through the NEW manual close.
        $this->actingAs($accountant)
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close", [
                'reason' => 'Correction posted; closing the period again.',
            ])->assertOk();

        $period->refresh();
        $this->assertSame(PeriodStatus::Closed, $period->status);
        $this->assertNotNull($period->reopened_at, 'the reopen stamp is kept');

        // 4. The hold releases BY ITSELF — the scheduler is unchanged by this lane.
        app(FiscalPeriodAutoLockService::class)->lockExpiredPeriods();

        $this->assertTrue($this->fiscalYear->refresh()->is_closed, 'the fiscal year closes once no reopened period is Open');
        $this->assertSame(PeriodStatus::Locked, $period->refresh()->status);
        $this->assertSame(FiscalPeriodAutoLockService::SYSTEM_ACTOR, $period->status_actor);
    }
}
