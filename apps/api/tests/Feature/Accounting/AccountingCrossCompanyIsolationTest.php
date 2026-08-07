<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W-8 finding F-1 (P0) — cross-COMPANY authorization break in the Accounting
 * module. Ticket: docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md
 *
 * Within ONE tenant, a principal bound to company A could:
 *   1. list company B's journal entries (JournalEntryController::index);
 *   2. read any one of them by id (::show);
 *   3. post company B's DRAFT entry (::post) — a state transition on another
 *      company's general ledger — and the entry was settled with the CALLER's
 *      currency rather than the entry's own company currency;
 *   4. list company B's chart of accounts (AccountController::index).
 *
 * The controllers resolved `$companyId` from CompanyContext and then filtered
 * on `tenant_id` ONLY, so `$companyId` never reached a query constraint (and
 * therefore no "unused variable" analyser fired).
 *
 * Cross-TENANT was already refused (404) — see AccountingTenantIsolationTest.
 * This suite pins the COMPANY axis, in both directions: every foreign-company
 * door is closed AND the caller's own company still works (no over-scoping).
 */
final class AccountingCrossCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Account $accountA;

    private Account $accountB;

    private JournalEntry $entryA;

    private JournalEntry $entryB;

    protected function setUp(): void
    {
        parent::setUp();

        // ONE tenant, TWO companies — the same-tenant cross-company surface.
        $this->tenant = Tenant::create([
            'name' => 'Cross Company Tenant',
            'slug' => 'cross-company-accounting',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Deliberately DIFFERENT currencies: company A is TND (scale 3),
        // company B is EUR (scale 2). F-1 settled B's entries at A's scale.
        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-XC',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-XC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Alice',
            'email' => 'alice-xc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->userA->givePermissionTo([
            'journal.view', 'journal.create', 'journal.post',
            'accounts.view', 'accounts.manage',
        ]);

        // Membership in company A ONLY — exactly the W-8 principal.
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->accountA = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'code' => '4000',
            'name' => 'XCA Revenue A',
            'type' => AccountType::Revenue,
        ]);
        $this->accountB = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'code' => '4001',
            'name' => 'XCB Revenue B',
            'type' => AccountType::Revenue,
        ]);

        $this->entryA = $this->makeDraftEntry($this->companyA, $this->accountA, 'JE-XC-A-000001', '11.110');
        $this->entryB = $this->makeDraftEntry($this->companyB, $this->accountB, 'JE-XC-B-000001', '777.770');
    }

    // ──────────────────────────────────────────────────────────────────
    // F-1a — JournalEntryController::index must not leak company B
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_index_excludes_other_company_entries(): void
    {
        $response = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/journal-entries');

        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');
        $ids = array_column($data, 'id');

        $this->assertContains($this->entryA->id, $ids, 'Company A must still see its own entry.');
        $this->assertNotContains($this->entryB->id, $ids, 'Company A must NOT see company B journal entries.');
        $this->assertSame(1, $response->json('meta.total'));
        $response->assertJsonMissing(['entry_number' => 'JE-XC-B-000001']);
    }

    // ──────────────────────────────────────────────────────────────────
    // F-1b — JournalEntryController::show must refuse company B
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_show_refuses_other_company_entry(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/journal-entries/'.$this->entryB->id)
            ->assertStatus(404);
    }

    public function test_journal_entry_show_still_serves_own_company_entry(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/journal-entries/'.$this->entryA->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $this->entryA->id);
    }

    // ──────────────────────────────────────────────────────────────────
    // F-1d — JournalEntryController::post must refuse company B
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_post_refuses_other_company_entry(): void
    {
        Event::fake([JournalEntryPosted::class]);

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/journal-entries/'.$this->entryB->id.'/post')
            ->assertStatus(404);

        // Pre-fix this dispatched JournalEntryPosted for company B's entry with
        // totals scaled to the CALLER's TND (3 dp) — the settlement-currency
        // half of F-1. No event may leave the refused door at all.
        Event::assertNotDispatched(JournalEntryPosted::class);

        $this->entryB->refresh();
        $this->assertSame(
            JournalEntryStatus::Draft,
            $this->entryB->status,
            "Company B's entry must remain draft after a refused cross-company post.",
        );
        $this->assertNull($this->entryB->posted_at);
        $this->assertNull($this->entryB->fiscal_hash);
        $this->assertNull($this->entryB->chain_sequence);
    }

    public function test_journal_entry_post_still_posts_own_company_entry(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/journal-entries/'.$this->entryA->id.'/post')
            ->assertStatus(200)
            ->assertJsonPath('data.status', JournalEntryStatus::Posted->value);

        $this->entryA->refresh();
        $this->assertSame(JournalEntryStatus::Posted, $this->entryA->status);
    }

    // ──────────────────────────────────────────────────────────────────
    // F-1 (settlement currency) — post() must settle in the ENTRY's company
    // currency, never the caller's. The controller passed
    // `$company->currency` (the CALLER's), which combined with the missing
    // company predicate settled company B's ledger at company A's scale.
    //
    // The fix stops the controller supplying a currency at all:
    // GeneralLedgerService::sealAndPersistEntry already derives it from
    // `$entry->company_id`. GeneralLedgerService is `final`, so the argument
    // cannot be observed with a mock; it is pinned behaviourally instead —
    // the JournalEntryPosted totals are emitted at the scale of the ENTRY's
    // own company currency, for two companies of the SAME tenant whose
    // currencies have different scales (TND = 3, EUR = 2).
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_post_settles_in_the_entry_company_currency_scale(): void
    {
        // Alice is a member of BOTH companies here, so each post is legitimate
        // and the only variable left is which company currency drives the scale.
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        Event::fake([JournalEntryPosted::class]);

        // Company A — TND, scale 3.
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/journal-entries/'.$this->entryA->id.'/post')
            ->assertStatus(200);

        // Company B — EUR, scale 2.
        $this->actingAsForCompany($this->userA, $this->companyB)
            ->postJson('/api/v1/journal-entries/'.$this->entryB->id.'/post')
            ->assertStatus(200);

        Event::assertDispatched(
            JournalEntryPosted::class,
            fn (JournalEntryPosted $e): bool => $e->entryId === $this->entryA->id
                && $e->totalDebit === '11.110'
                && $e->totalCredit === '11.110',
        );

        Event::assertDispatched(
            JournalEntryPosted::class,
            fn (JournalEntryPosted $e): bool => $e->entryId === $this->entryB->id
                && $e->totalDebit === '777.77'
                && $e->totalCredit === '777.77',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // F-1c — AccountController::index / show / update must refuse company B
    // ──────────────────────────────────────────────────────────────────

    public function test_account_index_excludes_other_company_accounts(): void
    {
        $response = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/accounts');

        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');
        $ids = array_column($data, 'id');

        $this->assertContains($this->accountA->id, $ids);
        $this->assertNotContains($this->accountB->id, $ids, 'Company A must NOT see company B accounts.');
        $response->assertJsonMissing(['code' => '4001']);
    }

    public function test_account_search_excludes_other_company_accounts(): void
    {
        $response = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/accounts?search=XCB');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_account_show_refuses_other_company_account(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/accounts/'.$this->accountB->id)
            ->assertStatus(404);
    }

    public function test_account_show_still_serves_own_company_account(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/accounts/'.$this->accountA->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $this->accountA->id);
    }

    public function test_account_update_refuses_other_company_account(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->patchJson('/api/v1/accounts/'.$this->accountB->id, ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->accountB->refresh();
        $this->assertSame('XCB Revenue B', $this->accountB->name);
    }

    public function test_account_update_still_updates_own_company_account(): void
    {
        $this->actingAsForCompany($this->userA, $this->companyA)
            ->patchJson('/api/v1/accounts/'.$this->accountA->id, ['name' => 'Renamed A'])
            ->assertStatus(200);

        $this->accountA->refresh();
        $this->assertSame('Renamed A', $this->accountA->name);
    }

    // ──────────────────────────────────────────────────────────────────

    private function makeDraftEntry(Company $company, Account $account, string $number, string $amount): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'entry_number' => $number,
            'entry_date' => now()->toDateString(),
            'description' => 'Cross-company fixture '.$number,
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'manual',
        ]);
        $entry->update(['source_id' => $entry->id]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $amount,
            'credit' => '0',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => '0',
            'credit' => $amount,
            'line_order' => 1,
        ]);

        return $entry->load('lines.account');
    }

    private function actingAsForCompany(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
