<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\OpeningBalancePosted;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
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

class OpeningBalanceBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private User $unauthorizedUser;

    private Account $cashAccount;

    private Account $bankAccount;

    private Account $obeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-ob-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-OB-123',
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
            'name' => 'OB Admin',
            'email' => 'ob-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Perms User',
            'email' => 'no-perms@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->unauthorizedUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create chart of accounts needed for GL opening balance
        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $this->bankAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5120',
            'name' => 'Bank Account',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $this->obeAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // 1. Create batch
    // ---------------------------------------------------------------

    public function test_create_accounting_opening_balance_batch(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches",
            [
                'type' => OpeningBatchType::Accounting->value,
                'name' => 'GL Opening 2025',
                'cutover_date' => '2025-01-01',
                'source_system' => 'Legacy ERP',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'ACCOUNTING');
        $response->assertJsonPath('data.name', 'GL Opening 2025');
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.source_system', 'Legacy ERP');

        $this->assertDatabaseHas('opening_balance_batches', [
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting->value,
            'name' => 'GL Opening 2025',
            'status' => OpeningBatchStatus::Draft->value,
        ]);
    }

    public function test_cannot_create_duplicate_unlocked_batch_of_same_type(): void
    {
        // Create first batch
        $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches",
            [
                'type' => OpeningBatchType::Accounting->value,
                'name' => 'GL Opening 2025 - First',
                'cutover_date' => '2025-01-01',
            ]
        )->assertStatus(201);

        // Attempt second batch of same type
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches",
            [
                'type' => OpeningBatchType::Accounting->value,
                'name' => 'GL Opening 2025 - Second',
                'cutover_date' => '2025-01-01',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BATCH_CREATION_FAILED');
    }

    // ---------------------------------------------------------------
    // 2. Import rows
    // ---------------------------------------------------------------

    public function test_import_rows_into_accounting_batch(): void
    {
        $batch = $this->createDraftBatch();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/import",
            [
                'rows' => [
                    [
                        'account_code' => '5100',
                        'debit' => '10000.00',
                        'credit' => '0.00',
                        'description' => 'Cash opening balance',
                    ],
                    [
                        'account_code' => '5120',
                        'debit' => '25000.00',
                        'credit' => '0.00',
                        'description' => 'Bank opening balance',
                    ],
                    [
                        'account_code' => '3900',
                        'debit' => '0.00',
                        'credit' => '35000.00',
                        'description' => 'OBE offset',
                    ],
                ],
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.row_count', 3);

        $this->assertDatabaseCount('opening_balance_import_rows', 3);

        // Verify rows stored with correct raw_data
        $rows = OpeningBalanceImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(3, $rows);
        $this->assertEquals(1, $rows[0]->row_number);
        $this->assertEquals('5100', $rows[0]->raw_data['account_code']);
        $this->assertEquals(OpeningImportRowStatus::Pending, $rows[0]->status);
    }

    public function test_import_validates_required_fields_for_accounting_rows(): void
    {
        $batch = $this->createDraftBatch();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/import",
            [
                'rows' => [
                    [
                        // Missing account_code
                        'debit' => '100.00',
                        'credit' => '0.00',
                    ],
                ],
            ]
        );

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // 3. Validate batch (balanced check)
    // ---------------------------------------------------------------

    public function test_validate_balanced_batch_succeeds(): void
    {
        $batch = $this->createBatchWithRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/validate"
        );

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
        $response->assertJsonPath('data.is_balanced', true);
        $response->assertJsonPath('data.valid_rows', 2);
        $response->assertJsonPath('data.invalid_rows', 0);
        $response->assertJsonPath('data.total_debit', '10000.00');
        $response->assertJsonPath('data.total_credit', '10000.00');
    }

    public function test_validate_unbalanced_batch_reports_errors(): void
    {
        $batch = $this->createBatchWithRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '5000.00', 'description' => 'Partial OBE'],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/validate"
        );

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.is_balanced', false);
    }

    public function test_validate_batch_with_invalid_account_code(): void
    {
        $batch = $this->createBatchWithRows([
            ['account_code' => '9999', 'debit' => '100.00', 'credit' => '0.00', 'description' => 'Bad account'],
            ['account_code' => '5100', 'debit' => '0.00', 'credit' => '100.00', 'description' => 'Cash'],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/validate"
        );

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.invalid_rows', 1);
    }

    // ---------------------------------------------------------------
    // 4. Preview batch
    // ---------------------------------------------------------------

    public function test_preview_batch_shows_planned_journal_entries(): void
    {
        $batch = $this->createBatchWithValidatedRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/preview"
        );

        $response->assertOk();
        $response->assertJsonPath('data.entry.is_historical', true);
        $response->assertJsonPath('data.entry.source_type', 'opening_balance');
        $response->assertJsonPath('data.totals.is_balanced', true);
        $response->assertJsonPath('data.totals.debit', '10000.00');
        $response->assertJsonPath('data.totals.credit', '10000.00');
        $this->assertCount(2, $response->json('data.lines'));
    }

    // ---------------------------------------------------------------
    // 5. Post batch — verify GL entries created
    // ---------------------------------------------------------------

    public function test_post_batch_creates_journal_entry_and_lines(): void
    {
        $batch = $this->createBatchWithValidatedRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash opening'],
            ['account_code' => '5120', 'debit' => '25000.00', 'credit' => '0.00', 'description' => 'Bank opening'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '35000.00', 'description' => 'OBE offset'],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        );

        $response->assertOk();

        // Verify journal entry created
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'opening_balance',
            'source_id' => $batch->id,
            'is_historical' => true,
        ]);

        $entry = JournalEntry::where('source_id', $batch->id)->first();
        $this->assertNotNull($entry);

        // Verify journal lines
        $lines = JournalLine::where('journal_entry_id', $entry->id)->orderBy('line_order')->get();
        $this->assertCount(3, $lines);

        // Verify batch is now Locked (not just Validated)
        $batch->refresh();
        $this->assertEquals(OpeningBatchStatus::Locked, $batch->status);
        $this->assertNotNull($batch->locked_at);
        $this->assertNotNull($batch->hash);
        $this->assertEquals($this->user->id, $batch->locked_by);

        // Verify all rows are posted
        $postedRows = OpeningBalanceImportRow::where('batch_id', $batch->id)
            ->where('status', OpeningImportRowStatus::Posted)
            ->count();
        $this->assertEquals(3, $postedRows);
    }

    public function test_cannot_post_already_posted_batch(): void
    {
        $batch = $this->createBatchWithValidatedRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ]);

        // Post the batch first time
        $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        )->assertOk();

        // Attempt to post again — should fail because batch is now Locked
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'POST_FAILED');
    }

    // ---------------------------------------------------------------
    // 6. Lock batch
    // ---------------------------------------------------------------

    public function test_lock_validated_batch(): void
    {
        $batch = $this->createValidatedBatchDirectly();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/lock"
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'LOCKED');
        $response->assertJsonPath('data.is_locked', true);

        $batch->refresh();
        $this->assertEquals(OpeningBatchStatus::Locked, $batch->status);
        $this->assertNotNull($batch->locked_at);
        $this->assertEquals($this->user->id, $batch->locked_by);
        $this->assertNotNull($batch->hash);
    }

    public function test_cannot_lock_draft_batch(): void
    {
        $batch = $this->createDraftBatch();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/lock"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BATCH_LOCK_FAILED');
    }

    // ---------------------------------------------------------------
    // 7. Delete draft batch
    // ---------------------------------------------------------------

    public function test_delete_draft_batch(): void
    {
        $batch = $this->createDraftBatch();

        // Add some rows first
        $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/import",
            [
                'rows' => [
                    ['account_code' => '5100', 'debit' => '100.00', 'credit' => '0.00'],
                ],
            ]
        )->assertOk();

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Batch deleted successfully');

        $this->assertDatabaseMissing('opening_balance_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('opening_balance_import_rows', ['batch_id' => $batch->id]);
    }

    public function test_cannot_delete_validated_batch(): void
    {
        $batch = $this->createValidatedBatchDirectly();

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BATCH_DELETE_FAILED');
    }

    // ---------------------------------------------------------------
    // 8. Reject operations on locked batch
    // ---------------------------------------------------------------

    public function test_cannot_import_rows_into_locked_batch(): void
    {
        $batch = $this->createLockedBatchDirectly();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/import",
            [
                'rows' => [
                    ['account_code' => '5100', 'debit' => '100.00', 'credit' => '0.00'],
                ],
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'IMPORT_FAILED');
    }

    public function test_cannot_delete_locked_batch(): void
    {
        $batch = $this->createLockedBatchDirectly();

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BATCH_DELETE_FAILED');
    }

    public function test_cannot_lock_already_locked_batch(): void
    {
        $batch = $this->createLockedBatchDirectly();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/lock"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BATCH_LOCK_FAILED');
    }

    // ---------------------------------------------------------------
    // 9. Authorization
    // ---------------------------------------------------------------

    public function test_unauthorized_user_cannot_create_batch(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches",
            [
                'type' => OpeningBatchType::Accounting->value,
                'name' => 'Unauthorized Batch',
                'cutover_date' => '2025-01-01',
            ]
        );

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_list_batches(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches"
        );

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_post_batch(): void
    {
        $batch = $this->createBatchWithValidatedRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ]);

        $response = $this->actingAs($this->unauthorizedUser)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        );

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_batches(): void
    {
        $response = $this->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches"
        );

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // 10. OpeningBalancePosted event dispatched
    // ---------------------------------------------------------------

    public function test_opening_balance_posted_event_dispatched_on_post(): void
    {
        Event::fake([OpeningBalancePosted::class]);

        $batch = $this->createBatchWithValidatedRows([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        )->assertOk();

        Event::assertDispatched(OpeningBalancePosted::class, function (OpeningBalancePosted $event) use ($batch): bool {
            return $event->batchId === $batch->id
                && $event->companyId === $this->company->id
                && $event->entryCount === 2;
        });
    }

    // ---------------------------------------------------------------
    // Additional coverage: list, show, status, types, rows
    // ---------------------------------------------------------------

    public function test_list_batches_for_company(): void
    {
        $this->createDraftBatch();

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches"
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_show_batch_details(): void
    {
        $batch = $this->createDraftBatch();

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.id', $batch->id);
        $response->assertJsonPath('data.type', 'ACCOUNTING');
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.is_editable', true);
        $response->assertJsonPath('data.is_deletable', true);
        $this->assertArrayHasKey('statistics', $response->json('data'));
    }

    public function test_get_batch_rows_paginated(): void
    {
        $batch = $this->createBatchWithRows([
            ['account_code' => '5100', 'debit' => '100.00', 'credit' => '0.00', 'description' => 'Row 1'],
            ['account_code' => '5120', 'debit' => '200.00', 'credit' => '0.00', 'description' => 'Row 2'],
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/rows?per_page=10"
        );

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_get_opening_batch_status(): void
    {
        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/status"
        );

        $response->assertOk();
        $this->assertArrayHasKey('types', $response->json('data'));
        $this->assertArrayHasKey('all_ready', $response->json('data'));
        $this->assertArrayHasKey('inventory_ready', $response->json('data'));
    }

    public function test_get_opening_batch_types(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/opening-batches/types');

        $response->assertOk();
        $types = $response->json('data');
        $this->assertNotEmpty($types);

        // Verify accounting type is present
        $accountingType = collect($types)->firstWhere('value', 'ACCOUNTING');
        $this->assertNotNull($accountingType);
        $this->assertTrue($accountingType['affects_gl']);
    }

    public function test_cannot_import_into_validated_batch(): void
    {
        $batch = $this->createValidatedBatchDirectly();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/import",
            [
                'rows' => [
                    ['account_code' => '5100', 'debit' => '100.00', 'credit' => '0.00'],
                ],
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'IMPORT_FAILED');
    }

    public function test_cannot_post_locked_batch(): void
    {
        $batch = $this->createLockedBatchDirectly();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/post"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'POST_FAILED');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createDraftBatch(): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => 'Test GL Opening',
            'cutover_date' => '2025-01-01',
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function createBatchWithRows(array $rows): OpeningBalanceBatch
    {
        $batch = $this->createDraftBatch();

        foreach ($rows as $index => $row) {
            OpeningBalanceImportRow::create([
                'batch_id' => $batch->id,
                'row_type' => 'GL',
                'row_number' => $index + 1,
                'raw_data' => $row,
                'status' => OpeningImportRowStatus::Pending,
            ]);
        }

        return $batch;
    }

    /**
     * Create a batch with rows that have been validated (status=VALID with mapped_data).
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function createBatchWithValidatedRows(array $rows): OpeningBalanceBatch
    {
        $batch = $this->createDraftBatch();

        foreach ($rows as $index => $row) {
            $accountCode = $row['account_code'];
            $account = Account::forCompany($this->company->id)
                ->where('code', $accountCode)
                ->active()
                ->firstOrFail();

            OpeningBalanceImportRow::create([
                'batch_id' => $batch->id,
                'row_type' => 'GL',
                'row_number' => $index + 1,
                'raw_data' => $row,
                'status' => OpeningImportRowStatus::Valid,
                'mapped_data' => [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'debit' => $row['debit'] ?? '0.00',
                    'credit' => $row['credit'] ?? '0.00',
                    'description' => $row['description'] ?? null,
                ],
            ]);
        }

        return $batch;
    }

    /**
     * Create a validated batch directly in the DB (bypasses postBatch API which has a bug).
     */
    private function createValidatedBatchDirectly(): OpeningBalanceBatch
    {
        $batch = OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => 'Test GL Opening - Validated',
            'cutover_date' => '2025-01-01',
            'status' => OpeningBatchStatus::Validated,
            'created_by' => $this->user->id,
            'validated_at' => now(),
            'validated_by' => $this->user->id,
        ]);

        foreach ([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ] as $index => $row) {
            $account = Account::forCompany($this->company->id)
                ->where('code', $row['account_code'])
                ->active()
                ->firstOrFail();

            OpeningBalanceImportRow::create([
                'batch_id' => $batch->id,
                'row_type' => 'GL',
                'row_number' => $index + 1,
                'raw_data' => $row,
                'status' => OpeningImportRowStatus::Posted,
                'mapped_data' => [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'debit' => $row['debit'],
                    'credit' => $row['credit'],
                    'description' => $row['description'],
                ],
            ]);
        }

        return $batch;
    }

    /**
     * Create a locked batch directly in the DB (bypasses postBatch API which has a bug).
     */
    private function createLockedBatchDirectly(): OpeningBalanceBatch
    {
        $batch = OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => 'Test GL Opening - Locked',
            'cutover_date' => '2025-01-01',
            'status' => OpeningBatchStatus::Locked,
            'created_by' => $this->user->id,
            'validated_at' => now()->subHour(),
            'validated_by' => $this->user->id,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
            'hash' => hash('sha256', 'test-locked-batch'),
        ]);

        foreach ([
            ['account_code' => '5100', 'debit' => '10000.00', 'credit' => '0.00', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.00', 'credit' => '10000.00', 'description' => 'OBE'],
        ] as $index => $row) {
            $account = Account::forCompany($this->company->id)
                ->where('code', $row['account_code'])
                ->active()
                ->firstOrFail();

            OpeningBalanceImportRow::create([
                'batch_id' => $batch->id,
                'row_type' => 'GL',
                'row_number' => $index + 1,
                'raw_data' => $row,
                'status' => OpeningImportRowStatus::Posted,
                'mapped_data' => [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'debit' => $row['debit'],
                    'credit' => $row['credit'],
                    'description' => $row['description'],
                ],
            ]);
        }

        return $batch;
    }
}
