<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Accounting\Application\Services\Reports\TrialBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L4 — W-8 F-3: the trial balance was CURRENCY-BLIND and internally inconsistent.
 *
 * One payload carried three different scales — `debit` at 3 dp (the raw DB
 * string), `credit` at 4 dp (a `bcmul` result) and a literal `"0.00"` for the
 * zero side — none of them derived from `companies.currency`. A EUR (scale-2)
 * company emitted byte-identically to a TND (scale-3) one.
 *
 * W-6 D3 is the same root: an empty period seeded its accumulators with the
 * literal `'0.00'` and never reached the report scale.
 *
 * Contract pinned here: EVERY emitted figure — both sides of every line and both
 * totals, populated or zero — carries exactly the company currency's scale.
 */
final class TrialBalanceCurrencyScaleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function makeCompany(string $currency, string $countryCode, string $locale): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => "TB {$currency} Company",
            'legal_name' => "TB {$currency} Company LLC",
            'tax_id' => 'TB'.$currency,
            'country_code' => $countryCode,
            'locale' => $locale,
            'timezone' => 'UTC',
            'currency' => $currency,
            'status' => CompanyStatus::Active,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TB Scale Tenant',
            'slug' => 'tb-scale-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeAccount(Company $company, string $code, AccountType $type): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => $code,
            'name' => "Account {$code}",
            'type' => $type,
        ]);
    }

    private function postEntry(Company $company, Account $debit, Account $credit, string $amount): void
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => '2026-06-15',
            'description' => 'Scale probe',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debit->id,
            'debit' => $amount,
            'credit' => '0.000',
            'line_order' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $credit->id,
            'debit' => '0.000',
            'credit' => $amount,
            'line_order' => 2,
        ]);
    }

    /**
     * @return array{lines: list<array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool, as_of_date: string}
     */
    private function generateFor(Company $company): array
    {
        $context = $this->app->make(CompanyContext::class);
        $context->clear();
        $context->setCompanyId($company->id);

        /** @var array{lines: list<array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool, as_of_date: string} $report */
        $report = $this->app->make(TrialBalanceService::class)->generate(
            $company->id,
            Carbon::parse('2026-12-31')->endOfDay(),
            false,
            false,
        );

        return $report;
    }

    public function test_a_eur_company_emits_every_figure_at_scale_two(): void
    {
        $company = $this->makeCompany('EUR', 'FR', 'fr_FR');
        $debit = $this->makeAccount($company, 'W8GL1', AccountType::Asset);
        $credit = $this->makeAccount($company, 'W8GL2', AccountType::Revenue);
        $this->postEntry($company, $debit, $credit, '777.770');

        $report = $this->generateFor($company);

        $debitLine = collect($report['lines'])->firstWhere('account_code', 'W8GL1');
        $creditLine = collect($report['lines'])->firstWhere('account_code', 'W8GL2');
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);

        $this->assertSame('777.77', $debitLine['debit'], 'EUR debit is emitted at the currency scale 2.');
        $this->assertSame('0.00', $debitLine['credit'], 'The zero side carries the SAME scale as the populated side.');
        $this->assertSame('777.77', $creditLine['credit'], 'EUR credit is emitted at scale 2, not the bcmul scale 4.');
        $this->assertSame('0.00', $creditLine['debit']);
        $this->assertSame('777.77', $report['total_debit']);
        $this->assertSame('777.77', $report['total_credit']);
        $this->assertTrue($report['is_balanced']);
    }

    public function test_a_tnd_company_emits_every_figure_at_scale_three(): void
    {
        $company = $this->makeCompany('TND', 'TN', 'fr_TN');
        $debit = $this->makeAccount($company, 'TN411', AccountType::Asset);
        $credit = $this->makeAccount($company, 'TN707', AccountType::Revenue);
        $this->postEntry($company, $debit, $credit, '777.770');

        $report = $this->generateFor($company);

        $debitLine = collect($report['lines'])->firstWhere('account_code', 'TN411');
        $creditLine = collect($report['lines'])->firstWhere('account_code', 'TN707');
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);

        $this->assertSame('777.770', $debitLine['debit'], 'TND debit keeps the millime.');
        $this->assertSame('0.000', $debitLine['credit']);
        $this->assertSame('777.770', $creditLine['credit']);
        $this->assertSame('777.770', $report['total_debit']);
        $this->assertSame('777.770', $report['total_credit']);
    }

    public function test_an_empty_period_still_reaches_the_currency_scale(): void
    {
        // W-6 D3: `total_debit: "0.00"` on a TND tenant, while every sibling
        // report answered at the report scale.
        $company = $this->makeCompany('TND', 'TN', 'fr_TN');
        $this->makeAccount($company, 'TN411', AccountType::Asset);

        $report = $this->generateFor($company);

        $this->assertSame([], $report['lines']);
        $this->assertSame('0.000', $report['total_debit']);
        $this->assertSame('0.000', $report['total_credit']);
        $this->assertTrue($report['is_balanced']);
    }

    /**
     * `emit()` is `decimalString()`'s sibling — a SEPARATE call to
     * `CurrencyScale::bcround`, so it needs its own rounding pin or a revert to
     * truncation here stays green. Every other fixture in this class has a zero
     * 4th decimal and cannot tell the two apart.
     *
     * See the Q4 ruling in
     * `docs/superpowers/reviews/2026-08-05-l4-api-precision-gate.md`.
     */
    public function test_emission_rounds_the_digits_the_currency_scale_cannot_hold(): void
    {
        $company = $this->makeCompany('EUR', 'FR', 'fr_FR');
        $debit = $this->makeAccount($company, 'W8GL1', AccountType::Asset);
        $credit = $this->makeAccount($company, 'W8GL2', AccountType::Revenue);
        $this->postEntry($company, $debit, $credit, '777.775');

        $report = $this->generateFor($company);

        $debitLine = collect($report['lines'])->firstWhere('account_code', 'W8GL1');
        $creditLine = collect($report['lines'])->firstWhere('account_code', 'W8GL2');

        $this->assertSame(
            '777.78',
            $debitLine['debit'],
            'bcround, not bcformat: truncation would emit 777.77.',
        );
        $this->assertSame(
            '777.78',
            $creditLine['credit'],
            'the credit side is derived through bcmul and must round the same way.',
        );
        $this->assertSame('777.78', $report['total_debit']);
        $this->assertSame('777.78', $report['total_credit']);
    }

    public function test_the_hierarchical_report_emits_at_the_currency_scale_too(): void
    {
        $company = $this->makeCompany('EUR', 'FR', 'fr_FR');
        $debit = $this->makeAccount($company, 'W8GL1', AccountType::Asset);
        $credit = $this->makeAccount($company, 'W8GL2', AccountType::Revenue);
        $this->postEntry($company, $debit, $credit, '777.770');

        $context = $this->app->make(CompanyContext::class);
        $context->clear();
        $context->setCompanyId($company->id);

        /** @var array{lines: list<array<string, mixed>>} $report */
        $report = $this->app->make(TrialBalanceService::class)->generate(
            $company->id,
            Carbon::parse('2026-12-31')->endOfDay(),
            false,
            true,
        );

        foreach ($report['lines'] as $line) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', (string) $line['debit']);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', (string) $line['credit']);
        }
    }
}
