<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Treasury spine BLOCKER-2: sealAndPersistEntry() must reject posting into a
 * fiscal period that EXISTS and is Closed for the entry date, but must NEVER
 * block posting when no period at all is configured for the date (absence of
 * period configuration is allowed — it would otherwise brick every company
 * that hasn't set up fiscal periods yet).
 */
final class PostingClosedPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Queued/console/projection GL posting runs with NO CompanyContext;
        // postEntryNow requires the currency to be passed explicitly (rule 20).
        app(CompanyContext::class)->clear();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        // Company creation synchronously auto-provisions 3 fiscal years (past,
        // current, future — CreateFiscalYearsForNewCompany) with the past year
        // fully Closed. That auto-provisioning is real production behavior, but
        // it makes period coverage depend on wall-clock "today" and would make
        // these guard tests non-deterministic (a "no periods configured"
        // scenario always collides with the auto-created past year, and
        // querying for "the" period covering a date becomes ambiguous once two
        // periods span it). Clear it here so each test controls its own period
        // fixture explicitly.
        FiscalPeriod::where('company_id', $this->company->id)->delete();
        FiscalYear::where('company_id', $this->company->id)->delete();

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Period Guard Poster',
            'email' => 'period-guard-poster@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '999998',
            'name' => 'Period Guard Test Suspense',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);
    }

    public function test_posting_into_a_closed_period_is_rejected(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'February 2025',
            'period_number' => 2,
            'start_date' => '2025-02-01',
            'end_date' => '2025-02-28',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        $entry = $this->makeDraftBalancedEntry('CLOSED-PERIOD-001', '2025-02-15');

        $this->expectException(ClosedFiscalPeriodException::class);

        try {
            DB::transaction(function () use ($entry): void {
                app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
            });
        } finally {
            // The rejected post must not have left the entry Posted.
            $fresh = $entry->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(JournalEntryStatus::Draft, $fresh->status);
        }
    }

    public function test_posting_into_an_open_period_succeeds(): void
    {
        $year = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $this->company->id,
            'name' => 'March 2025',
            'period_number' => 3,
            'start_date' => '2025-03-01',
            'end_date' => '2025-03-31',
            'status' => PeriodStatus::Open,
        ]);

        $entry = $this->makeDraftBalancedEntry('OPEN-PERIOD-001', '2025-03-15');

        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
        });

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(JournalEntryStatus::Posted, $fresh->status);
        $this->assertNotNull($fresh->chain_sequence);
        $this->assertNotNull($fresh->fiscal_hash);
    }

    public function test_posting_for_a_company_with_no_periods_configured_is_not_bricked(): void
    {
        // Deliberately create NO FiscalYear/FiscalPeriod rows for this company.
        $entry = $this->makeDraftBalancedEntry('NO-PERIODS-001', '2025-04-10');

        DB::transaction(function () use ($entry): void {
            app(GeneralLedgerService::class)->postEntryNow($entry, $this->user, 'TND');
        });

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(JournalEntryStatus::Posted, $fresh->status);
        $this->assertNotNull($fresh->chain_sequence);
        $this->assertNotNull($fresh->fiscal_hash);
    }

    private function makeDraftBalancedEntry(string $entryNumber, string $entryDate): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => $entryNumber,
            'entry_date' => $entryDate,
            'description' => 'Closed-period guard test entry',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account->id,
            'debit' => '100.000',
            'credit' => '0',
            'description' => 'Debit leg',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account->id,
            'debit' => '0',
            'credit' => '100.000',
            'description' => 'Credit leg',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }
}
