<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
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
use Tests\Traits\AssertsApiValidation;

class CreateJournalEntryTest extends TestCase
{
    use AssertsApiValidation;
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
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
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

        // Create test accounts
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

    public function test_user_can_create_journal_entry(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Cash sale',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '0.00',
                    'description' => 'Cash received',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '100.00',
                    'description' => 'Sales revenue',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertNotEmpty($response->json('data.entry_number'));
    }

    public function test_manual_journal_entry_create_dispatches_audit_event(): void
    {
        Event::fake([JournalEntryCreated::class]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Manual accrual',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.000',
                    'credit' => '0.000',
                    'description' => 'Cash received',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.000',
                    'credit' => '100.000',
                    'description' => 'Revenue accrual',
                ],
            ],
        ]);

        $response->assertCreated();

        Event::assertDispatched(
            JournalEntryCreated::class,
            fn (JournalEntryCreated $event): bool => $event->journalEntryId === $response->json('data.id')
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->entryNumber === $response->json('data.entry_number')
                && $event->entryDate === '2025-01-15'
                && $event->entryType === 'manual'
                && $event->sourceType === 'manual'
                && $event->sourceId === $response->json('data.id')
                && bccomp($event->totalDebit, '100.000', 3) === 0
                && bccomp($event->totalCredit, '100.000', 3) === 0
        );
    }

    public function test_manual_journal_entry_post_dispatches_audit_event(): void
    {
        // Uses today's date (not the fixed '2025-01-15' used elsewhere in this
        // file) because this is the one test in the file that actually POSTS
        // the entry: sealAndPersistEntry() now rejects posting into a Closed
        // fiscal period (spine BLOCKER-2), and company auto-provisioning
        // (CreateFiscalYearsForNewCompany) closes the whole PAST fiscal year
        // relative to wall-clock "today" — so a hardcoded past date drifts
        // into a closed period as real time advances. Today's date always
        // falls in the current (open) period.
        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Manual posting',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.000',
                    'credit' => '0.000',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.000',
                    'credit' => '100.000',
                ],
            ],
        ]);
        $createResponse->assertCreated();

        Event::fake([JournalEntryPosted::class]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/journal-entries/{$createResponse->json('data.id')}/post");

        $response->assertOk()
            ->assertJsonPath('data.status', 'posted');

        Event::assertDispatched(
            JournalEntryPosted::class,
            fn (JournalEntryPosted $event): bool => $event->entryId === $createResponse->json('data.id')
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->entryNumber === $createResponse->json('data.entry_number')
                && bccomp($event->totalDebit, '100.000', 3) === 0
                && bccomp($event->totalCredit, '100.000', 3) === 0
        );
    }

    public function test_journal_entry_requires_balanced_lines(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Unbalanced entry',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '50.00',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals('UNBALANCED_ENTRY', $response->json('error.code'));
    }

    public function test_journal_entry_requires_at_least_two_lines(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Single line entry',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_journal_entry_line_cannot_have_both_debit_and_credit(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Invalid line',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '50.00',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '50.00',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals('INVALID_LINE', $response->json('error.code'));
    }

    public function test_journal_entry_requires_valid_accounts(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Invalid account',
            'lines' => [
                [
                    'account_id' => '00000000-0000-0000-0000-000000000000',
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '100.00',
                ],
            ],
        ]);

        $this->assertApiValidationErrors($response, ['lines.0.account_id']);
    }

    public function test_unauthorized_user_cannot_create_journal_entry(): void
    {
        $this->user->revokePermissionTo('journal.create');

        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Test entry',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_journal_entry_generates_unique_number(): void
    {
        // Create first entry
        $response1 = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'First entry',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ]);

        // Create second entry
        $response2 = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Second entry',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => '50.00', 'credit' => '0.00'],
                ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '50.00'],
            ],
        ]);

        $this->assertNotEquals(
            $response1->json('data.entry_number'),
            $response2->json('data.entry_number')
        );
    }

    /**
     * Wave 2A Item 4: `GET /journal-entries/{id}` must return `account_code`
     * / `account_name` per line — the JE detail page ("Compte" column) reads
     * exactly those two fields and renders blank without them. Pre-existing
     * defect (JournalLineData::toArray never emitted them; predates the
     * treasury spine — confirmed unchanged at base 6a3292b42).
     */
    public function test_show_journal_entry_includes_account_code_and_name_per_line(): void
    {
        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Cash sale',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => '0.00',
                    'credit' => '100.00',
                ],
            ],
        ]);
        $createResponse->assertCreated();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/journal-entries/{$createResponse->json('data.id')}");

        $response->assertOk();

        /** @var array<int, array{account_id: string, account_code: string, account_name: string}> $lines */
        $lines = $response->json('data.lines');
        $this->assertCount(2, $lines);

        $byAccountId = [];
        foreach ($lines as $line) {
            $byAccountId[$line['account_id']] = $line;
        }

        $this->assertSame('1100', $byAccountId[$this->cashAccount->id]['account_code']);
        $this->assertSame('Cash', $byAccountId[$this->cashAccount->id]['account_name']);
        $this->assertSame('4000', $byAccountId[$this->revenueAccount->id]['account_code']);
        $this->assertSame('Sales Revenue', $byAccountId[$this->revenueAccount->id]['account_name']);
    }

    public function test_multi_line_journal_entry(): void
    {
        $taxAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'entry_date' => '2025-01-15',
            'description' => 'Sale with tax',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => '120.00', 'credit' => '0.00', 'description' => 'Cash received'],
                ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '100.00', 'description' => 'Sales'],
                ['account_id' => $taxAccount->id, 'debit' => '0.00', 'credit' => '20.00', 'description' => 'VAT collected'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data.lines'));
    }
}
