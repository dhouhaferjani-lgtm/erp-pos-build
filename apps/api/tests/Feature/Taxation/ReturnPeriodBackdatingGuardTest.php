<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Taxation\Application\Services\VatPeriodBackdatingGuard;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Shared\Contracts\Taxation\PeriodBackdatingGuardInterface;
use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use App\Shared\Exceptions\ReturnPeriodLockedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * T3 (plan CF §3) — the shared return-note backdating guard, over BOTH period
 * tables. [PG]
 *
 * CF-D3. The owner ruling requires option 2 of the guided cancel flow ("products
 * were already returned, on date X") to follow "the same period rules as a
 * manually-dated return note", so the rule lives behind one Shared contract that
 * both the manual confirm route and the composite go through.
 *
 * ── READ THIS BEFORE TRUSTING CF-D3's IMPACT CLAIM ──────────────────────────
 * CF-D3 justifies keying on both tables with "Both are absent-permits, so no
 * launch tenant regresses." The CONTRACT half is true and pinned below
 * ({@see test_no_period_row_in_either_table_permits_the_date}). The IMPACT half is
 * **false for `fiscal_periods`**, and this class pins the real behaviour rather
 * than the assumed one:
 *
 *   `CreateFiscalYearsForNewCompany` runs SYNCHRONOUSLY on `CompanyCreated` and
 *   calls `FiscalYearCreationService::createFiscalYearsForCompany()`, which
 *   materialises past/current/future fiscal years as 12 monthly periods each — 36
 *   rows for a TN company — and `determinePeriodStatus()` marks every period whose
 *   `end_date` is more than ONE MONTH old as `PeriodStatus::Closed`, with
 *   `closed_by = null` because no human decided it.
 *
 * So `fiscal_periods` is never absent in practice: for EVERY tenant, from the
 * moment the company row is written, every month older than about one month is
 * already Closed. `returned_on` more than ~a month in the past will therefore be
 * refused with `RETURN_PERIOD_LOCKED` on a brand-new tenant. That is arguably the
 * correct fiscal behaviour — you should not date a stock document into shut books
 * — but it is a ROUTINE refusal the plan reasoned would not occur, so the modal
 * copy and the owner's expectations for option 2 have to account for it.
 * Escalated in the CF report; the guard itself is implemented exactly as ruled.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class ReturnPeriodBackdatingGuardTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    /**
     * A date the company's AUTO-CREATED fiscal period leaves Open: the current
     * month. Everything older than roughly one month is auto-closed.
     */
    private Carbon $openDate;

    private PeriodBackdatingGuardInterface $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-backdating');
        $this->guard = app(PeriodBackdatingGuardInterface::class);
        $this->openDate = Carbon::today();
    }

    public function test_the_contract_resolves_to_the_backdating_guard(): void
    {
        self::assertInstanceOf(VatPeriodBackdatingGuard::class, $this->guard);
    }

    /**
     * THE contract property. Absent must permit, or every return note on every
     * tenant that has not begun declaring becomes unconfirmable and the goods never
     * re-enter stock. Both tables are emptied here so "absent" really means absent
     * (see the class docblock — a real company is never in this state).
     */
    public function test_no_period_row_in_either_table_permits_the_date(): void
    {
        FiscalPeriod::query()->delete();
        VatPeriod::query()->delete();

        $date = Carbon::parse('2026-03-15');

        self::assertNull($this->guard->backdatingRefusalCode($this->cfCompany->id, $date));
        $this->guard->assertBackdatingPeriodIsOpen($this->cfCompany->id, $date, 'RN-2026-0001');

        self::assertSame(0, FiscalPeriod::query()->count(), 'The absent case must really be absent.');
    }

    /**
     * The falsified-premise pin. If a future lane changes the auto-creation policy
     * (or the ratchet is re-argued), this test tells whoever changes it that lane CF
     * depends on the answer.
     */
    public function test_a_new_company_already_has_auto_closed_fiscal_periods_in_the_recent_past(): void
    {
        $twoMonthsAgo = Carbon::today()->subMonths(2);

        self::assertGreaterThan(
            0,
            FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->count(),
            'CreateFiscalYearsForNewCompany should have materialised periods on CompanyCreated.',
        );

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodLocked->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $twoMonthsAgo),
            'A brand-new tenant already refuses a two-month-old return date — CF-D3 assumed it would not.',
        );
    }

    public function test_the_current_month_is_permitted_on_a_new_company(): void
    {
        self::assertNull($this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate));
    }

    public function test_an_open_vat_period_permits_the_date(): void
    {
        $this->vatPeriodCovering($this->openDate, VatPeriodStatus::Open);

        self::assertNull($this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate));
    }

    public function test_a_closed_vat_period_refuses_with_return_period_closed(): void
    {
        $period = $this->vatPeriodCovering($this->openDate, VatPeriodStatus::Closed);

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodClosed->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate),
        );

        try {
            $this->guard->assertBackdatingPeriodIsOpen($this->cfCompany->id, $this->openDate, 'RN-2026-0001');
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException $e) {
            self::assertSame(ReturnPeriodRefusalCode::PeriodClosed, $e->refusalCode);
            self::assertSame('RN-2026-0001', $e->documentNumber);
            self::assertSame($this->openDate->toDateString(), $e->returnDate);
            self::assertSame($period->label, $e->periodLabel);
            // A CLOSED period can be reopened by an accountant, so the modal may
            // offer that remedy. A FILED one cannot — see the next test.
            self::assertTrue($e->refusalCode->isRecoverable());
        }
    }

    public function test_a_filed_vat_period_refuses_with_return_period_filed_and_is_not_recoverable(): void
    {
        $this->vatPeriodCovering($this->openDate, VatPeriodStatus::Filed);

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodFiled->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate),
        );

        try {
            $this->guard->assertBackdatingPeriodIsOpen($this->cfCompany->id, $this->openDate, 'RN-2026-0002');
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException $e) {
            self::assertSame(ReturnPeriodRefusalCode::PeriodFiled, $e->refusalCode);
            self::assertFalse($e->refusalCode->isRecoverable());
        }
    }

    public function test_a_closed_fiscal_period_refuses_with_return_period_locked(): void
    {
        $this->replaceFiscalPeriodCovering($this->openDate, PeriodStatus::Closed);

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodLocked->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate),
        );

        try {
            $this->guard->assertBackdatingPeriodIsOpen($this->cfCompany->id, $this->openDate, 'RN-2026-0003');
            self::fail('Expected ReturnPeriodLockedException.');
        } catch (ReturnPeriodLockedException $e) {
            self::assertSame(ReturnPeriodRefusalCode::PeriodLocked, $e->refusalCode);
            // `isDateInClosedFiscalPeriod()` is a boolean seam, so no label is
            // available — the message names the date instead.
            self::assertNull($e->periodLabel);
        }
    }

    public function test_a_locked_fiscal_period_also_refuses_with_return_period_locked(): void
    {
        $this->replaceFiscalPeriodCovering($this->openDate, PeriodStatus::Locked);

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodLocked->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate),
        );
    }

    /**
     * A period that does not COVER the date must not refuse it — otherwise closing
     * one month would lock every other month too.
     */
    public function test_a_locked_period_that_does_not_cover_the_date_permits_it(): void
    {
        FiscalPeriod::query()->delete();
        $this->vatPeriod('2026-02-01', '2026-02-28', VatPeriodStatus::Filed);
        $this->fiscalPeriod('2026-02-01', '2026-02-28', PeriodStatus::Closed);

        self::assertNull($this->guard->backdatingRefusalCode(
            $this->cfCompany->id,
            Carbon::parse('2026-03-15'),
        ));
    }

    /**
     * Tenancy is database-per-tenant, but company scoping inside a tenant database
     * still has to hold: another company's shut books must not refuse this
     * company's return.
     */
    public function test_another_companys_locked_period_does_not_refuse_the_date(): void
    {
        FiscalPeriod::query()->delete();

        $other = Company::create([
            'tenant_id' => $this->cfTenant->id,
            'name' => 'CF Other Company',
            'legal_name' => 'CF Other Company SARL',
            'tax_id' => 'CF-TAX-2',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        VatPeriod::create([
            'company_id' => $other->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'Other company period',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'status' => VatPeriodStatus::Filed,
        ]);

        // The second company's own auto-created periods must not leak either.
        FiscalPeriod::query()->where('company_id', '!=', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Closed,
        ]);

        self::assertNull($this->guard->backdatingRefusalCode(
            $this->cfCompany->id,
            Carbon::parse('2026-03-15'),
        ));
    }

    /**
     * The VAT verdict wins when both tables lock the date, because CLOSED vs FILED
     * carries the remedy the caller needs and `RETURN_PERIOD_LOCKED` does not.
     */
    public function test_the_vat_refusal_takes_precedence_over_the_fiscal_one(): void
    {
        $this->vatPeriodCovering($this->openDate, VatPeriodStatus::Filed);
        $this->replaceFiscalPeriodCovering($this->openDate, PeriodStatus::Closed);

        self::assertSame(
            ReturnPeriodRefusalCode::PeriodFiled->value,
            $this->guard->backdatingRefusalCode($this->cfCompany->id, $this->openDate),
        );
    }

    private function vatPeriodCovering(Carbon $date, VatPeriodStatus $status): VatPeriod
    {
        return $this->vatPeriod(
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
            $status,
        );
    }

    private function vatPeriod(string $start, string $end, VatPeriodStatus $status): VatPeriod
    {
        return VatPeriod::create([
            'company_id' => $this->cfCompany->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => Carbon::parse($start)->format('F Y'),
            'period_start' => $start,
            'period_end' => $end,
            'status' => $status,
        ]);
    }

    /**
     * Replace whatever auto-created period covers the date with one of the given
     * status. The resolver is order-independent — it refuses if ANY covering row is
     * shut — so leaving the auto-created row in place would make an "Open permits"
     * assertion vacuous.
     */
    private function replaceFiscalPeriodCovering(Carbon $date, PeriodStatus $status): FiscalPeriod
    {
        FiscalPeriod::query()
            ->where('company_id', $this->cfCompany->id)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->delete();

        return $this->fiscalPeriod(
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
            $status,
        );
    }

    private function fiscalPeriod(string $start, string $end, PeriodStatus $status): FiscalPeriod
    {
        $year = FiscalYear::create([
            'company_id' => $this->cfCompany->id,
            'name' => Carbon::parse($start)->format('Y').'-'.bin2hex(random_bytes(2)),
            'start_date' => Carbon::parse($start)->copy()->startOfYear()->toDateString(),
            'end_date' => Carbon::parse($start)->copy()->endOfYear()->toDateString(),
        ]);

        return FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->cfCompany->id,
            'name' => Carbon::parse($start)->format('F Y'),
            'period_number' => (int) Carbon::parse($start)->format('n'),
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
            'closed_at' => $status === PeriodStatus::Open ? null : now(),
            'closed_by' => $status === PeriodStatus::Open ? null : $this->cfUser->id,
        ]);
    }
}
