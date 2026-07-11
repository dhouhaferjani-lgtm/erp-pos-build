<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury spine Wave B gate (Fix 2): posting a manual journal entry whose
 * entry_date falls inside a CLOSED fiscal period must return HTTP 422 with
 * error.code === 'BUSINESS_ERROR' (ClosedFiscalPeriodException now extends
 * \DomainException, which the generic render handler maps to 422 — previously
 * it extended \RuntimeException and surfaced as an unhandled 500).
 *
 * The endpoint posts a PRE-EXISTING Draft, so a rejected post must leave the
 * entry Draft (it must NOT be orphaned or mutated).
 */
final class PostJournalEntryClosedPeriodHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Closed Period Tenant',
            'slug' => 'closed-period-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Period Company',
            'legal_name' => 'Closed Period Company LLC',
            'tax_id' => 'TAX-CP-1',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Reset auto-provisioned fiscal years/periods so we control the fixture:
        // one fiscal year with a single CLOSED period covering 2025-02-15.
        FiscalPeriod::where('company_id', $this->company->id)->delete();
        FiscalYear::where('company_id', $this->company->id)->delete();

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
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Period User',
            'email' => 'closed-period-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['journal.view', 'journal.create', 'journal.post']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
        ]);
    }

    public function test_posting_draft_into_closed_period_returns_422_business_error_and_keeps_draft(): void
    {
        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-02-15',
            'description' => 'Entry dated inside a closed period',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => '100.000', 'credit' => '0.000'],
                ['account_id' => $this->revenueAccount->id, 'debit' => '0.000', 'credit' => '100.000'],
            ],
        ]);
        $createResponse->assertCreated();
        $entryId = $createResponse->json('data.id');
        $this->assertSame('draft', $createResponse->json('data.status'));

        $postResponse = $this->actingAs($this->user)
            ->postJson("/api/v1/journal-entries/{$entryId}/post");

        $postResponse->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_ERROR');

        // The rejected post must leave the pre-existing Draft untouched — never
        // orphaned or mutated to Posted.
        $fresh = JournalEntry::query()->find($entryId);
        $this->assertInstanceOf(JournalEntry::class, $fresh);
        $this->assertSame(JournalEntryStatus::Draft, $fresh->status);
        $this->assertNull($fresh->chain_sequence);
        $this->assertNull($fresh->fiscal_hash);
    }
}
