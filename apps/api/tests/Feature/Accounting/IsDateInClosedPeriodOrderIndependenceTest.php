<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Treasury spine Wave B gate (Fix 3): FiscalPeriodResolverService::isDateInClosedPeriod
 * must be ORDER-INDEPENDENT. When two periods overlap a date — one Open, one
 * Closed — the previous ->first()->isClosed() form could sample the Open period
 * and wrongly allow a post into a date that is ALSO covered by a Closed period.
 *
 * The check must return true iff a covering Closed-or-Locked period EXISTS,
 * regardless of insertion / row order. Absence of any covering period must
 * still return false (posting allowed).
 */
final class IsDateInClosedPeriodOrderIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private FiscalYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        // Company creation auto-provisions fiscal years/periods (see
        // PostingClosedPeriodGuardTest). Clear them so each test owns its fixture.
        FiscalPeriod::where('company_id', $this->company->id)->delete();
        FiscalYear::where('company_id', $this->company->id)->delete();

        $this->year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
    }

    public function test_true_when_open_period_inserted_before_closed_period(): void
    {
        $this->makePeriod('Open span', 1, '2025-01-01', '2025-12-31', PeriodStatus::Open);
        $this->makePeriod('Closed month', 2, '2025-02-01', '2025-02-28', PeriodStatus::Closed);

        $resolver = app(FiscalPeriodResolverService::class);

        $this->assertTrue(
            $resolver->isDateInClosedPeriod($this->company->id, Carbon::parse('2025-02-15'))
        );
    }

    public function test_true_when_closed_period_inserted_before_open_period(): void
    {
        $this->makePeriod('Closed month', 2, '2025-02-01', '2025-02-28', PeriodStatus::Closed);
        $this->makePeriod('Open span', 1, '2025-01-01', '2025-12-31', PeriodStatus::Open);

        $resolver = app(FiscalPeriodResolverService::class);

        $this->assertTrue(
            $resolver->isDateInClosedPeriod($this->company->id, Carbon::parse('2025-02-15'))
        );
    }

    public function test_locked_period_also_counts_as_closed(): void
    {
        $this->makePeriod('Open span', 1, '2025-01-01', '2025-12-31', PeriodStatus::Open);
        $this->makePeriod('Locked month', 3, '2025-03-01', '2025-03-31', PeriodStatus::Locked);

        $resolver = app(FiscalPeriodResolverService::class);

        $this->assertTrue(
            $resolver->isDateInClosedPeriod($this->company->id, Carbon::parse('2025-03-15'))
        );
    }

    public function test_false_when_only_open_period_covers_the_date(): void
    {
        $this->makePeriod('Open month', 4, '2025-04-01', '2025-04-30', PeriodStatus::Open);

        $resolver = app(FiscalPeriodResolverService::class);

        $this->assertFalse(
            $resolver->isDateInClosedPeriod($this->company->id, Carbon::parse('2025-04-10'))
        );
    }

    public function test_false_when_no_period_covers_the_date(): void
    {
        $this->makePeriod('Closed month', 2, '2025-02-01', '2025-02-28', PeriodStatus::Closed);

        $resolver = app(FiscalPeriodResolverService::class);

        // Date outside every configured period → not blocked.
        $this->assertFalse(
            $resolver->isDateInClosedPeriod($this->company->id, Carbon::parse('2025-07-01'))
        );
    }

    private function makePeriod(string $name, int $number, string $start, string $end, PeriodStatus $status): void
    {
        FiscalPeriod::create([
            'fiscal_year_id' => $this->year->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'period_number' => $number,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
            'closed_at' => $status === PeriodStatus::Open ? null : now(),
        ]);
    }
}
