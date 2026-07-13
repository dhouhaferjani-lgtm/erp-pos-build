<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GenerateRecurringExpensesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_active_template_creates_one_complete_draft_advances_origin_cursor_and_notifies_only_company_actors(): void
    {
        Carbon::setTestNow('2026-02-23 10:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->for($tenant)->create();
        $siblingCompany = Company::factory()->tunisia()->for($tenant)->create();
        $author = User::factory()->for($tenant)->create();
        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Office rent',
        ]);
        $partner = Partner::factory()->supplier()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $template = $this->template($tenant, $company, $author, [
            'name' => 'Tunis office rent',
            'expense_category_id' => $category->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'payment_repository_id' => $repository->id,
            'vendor_name' => 'Landlord SARL',
            'amount' => '1190.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
            'vat_amount' => '190.000',
            'notes' => 'Monthly office rent',
            'start_date' => '2026-01-31',
            'next_due_date' => '2026-02-28',
            'lead_days' => 5,
        ]);

        $recipient = $this->recipient($tenant, $company, '00000000-0000-4000-8000-000000000101', true);
        $inactiveMembership = $this->recipient($tenant, $company, '00000000-0000-4000-8000-000000000102', false);
        $siblingRecipient = $this->recipient($tenant, $siblingCompany, '00000000-0000-4000-8000-000000000103', true);

        $this->template($tenant, $company, $author, [
            'name' => 'Paused ignored template',
            'status' => RecurrenceStatus::Paused,
            'start_date' => '2026-01-01',
            'next_due_date' => '2026-02-23',
            'lead_days' => 0,
        ]);
        $this->template($tenant, $company, $author, [
            'name' => 'Not yet in lead window',
            'start_date' => '2026-01-28',
            'next_due_date' => '2026-02-28',
            'lead_days' => 4,
        ]);

        $exit = Artisan::call('expenses:generate-recurring');
        self::assertSame(0, $exit, Artisan::output());

        $expense = Document::query()->where('type', DocumentType::Expense)->sole();
        $metadata = $expense->expenseMetadata;
        self::assertNotNull($metadata);
        self::assertSame($tenant->id, $expense->tenant_id);
        self::assertSame($company->id, $expense->company_id);
        self::assertSame($partner->id, $expense->partner_id);
        self::assertSame(DocumentStatus::Draft, $expense->status);
        self::assertSame('2026-02-28', $expense->document_date->toDateString());
        self::assertSame('TND', $expense->currency);
        self::assertSame('1000.000', $expense->subtotal);
        self::assertSame('190.000', $expense->tax_amount);
        self::assertSame('1190.000', $expense->total);
        self::assertSame('Monthly office rent', $expense->notes);

        self::assertSame($category->id, $metadata->expense_category_id);
        self::assertSame($method->id, $metadata->payment_method_id);
        self::assertSame($repository->id, $metadata->payment_repository_id);
        self::assertSame('Landlord SARL', $metadata->vendor_name);
        self::assertSame('19.00', $metadata->vat_rate);
        self::assertSame('80.00', $metadata->vat_deductible_percent);
        self::assertNull($metadata->payment_date);
        self::assertFalse($metadata->is_paid);
        self::assertSame($template->id, $metadata->recurrence_template_id);
        self::assertSame("recurring:{$template->id}:2026-02", $metadata->idempotency_key);

        $template->refresh();
        self::assertSame('2026-03-31', $template->next_due_date->toDateString());
        self::assertSame(RecurrenceStatus::Active, $template->status);

        $row = DB::table('notifications')
            ->where('notifiable_id', $recipient->id)
            ->where('type', 'expense.recurring.generated')
            ->sole();
        self::assertSame([
            'template_name' => 'Tunis office rent',
            'amount' => '1190.000',
            'currency' => 'TND',
            'due_date' => '2026-02-28',
            'deep_link' => "/expenses/{$expense->id}",
            'alert_type' => 'expense.recurring.generated',
        ], json_decode((string) $row->data, true, flags: JSON_THROW_ON_ERROR));
        self::assertDatabaseMissing('notifications', ['notifiable_id' => $inactiveMembership->id]);
        self::assertDatabaseMissing('notifications', ['notifiable_id' => $siblingRecipient->id]);
    }

    public function test_cursor_ends_only_after_next_occurrence_passes_the_inclusive_end_date(): void
    {
        Carbon::setTestNow('2026-02-28 08:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->for($tenant)->create();
        $author = User::factory()->for($tenant)->create();
        $inclusive = $this->template($tenant, $company, $author, [
            'name' => 'Inclusive end',
            'start_date' => '2026-01-31',
            'next_due_date' => '2026-02-28',
            'end_date' => '2026-03-31',
            'lead_days' => 0,
        ]);
        $past = $this->template($tenant, $company, $author, [
            'name' => 'Past end',
            'start_date' => '2025-12-28',
            'next_due_date' => '2026-02-28',
            'end_date' => '2026-02-28',
            'lead_days' => 0,
        ]);

        $exit = Artisan::call('expenses:generate-recurring');
        self::assertSame(0, $exit, Artisan::output());

        $inclusive->refresh();
        self::assertSame('2026-03-31', $inclusive->next_due_date->toDateString());
        self::assertSame(RecurrenceStatus::Active, $inclusive->status);
        $past->refresh();
        self::assertSame('2026-03-28', $past->next_due_date->toDateString());
        self::assertSame(RecurrenceStatus::Ended, $past->status);
    }

    public function test_existing_same_period_short_circuits_creation_advances_once_and_never_re_notifies(): void
    {
        Carbon::setTestNow('2026-07-31 08:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->for($tenant)->create();
        $author = User::factory()->for($tenant)->create();
        $template = $this->template($tenant, $company, $author, [
            'start_date' => '2026-01-31',
            'next_due_date' => '2026-07-31',
            'lead_days' => 0,
        ]);
        $key = "recurring:{$template->id}:2026-07";
        $service = app(ExpenseService::class);
        $payload = [
            'company_id' => $company->id,
            'document_date' => '2026-07-31',
            'is_paid' => false,
            'total' => '100.000',
            'idempotency_key' => $key,
        ];

        $created = $service->create($payload, $author);
        $replayed = $service->create($payload, $author);
        self::assertTrue($created->wasRecentlyCreated);
        self::assertFalse($replayed->wasRecentlyCreated);
        self::assertSame($created->id, $replayed->id);

        $this->recipient($tenant, $company, '00000000-0000-4000-8000-000000000201', true);
        self::assertSame(0, Artisan::call('expenses:generate-recurring'));

        self::assertSame(1, Document::query()->where('type', DocumentType::Expense)->count());
        $template->refresh();
        self::assertSame('2026-08-31', $template->next_due_date->toDateString());
        self::assertSame($template->id, $created->expenseMetadata?->fresh()?->recurrence_template_id);
        self::assertSame(0, DB::table('notifications')->where('type', 'expense.recurring.generated')->count());

        self::assertSame(0, Artisan::call('expenses:generate-recurring'));
        self::assertSame(1, Document::query()->where('type', DocumentType::Expense)->count());
        self::assertSame('2026-08-31', $template->fresh()?->next_due_date->toDateString());
        self::assertSame(0, DB::table('notifications')->where('type', 'expense.recurring.generated')->count());
    }

    public function test_missing_author_uses_first_active_admin_of_same_tenant_and_two_companies_never_cross_stamp(): void
    {
        Carbon::setTestNow('2026-07-13 08:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $firstCompany = Company::factory()->tunisia()->for($tenant)->create([
            'id' => '00000000-0000-4000-8000-000000000301',
        ]);
        $secondCompany = Company::factory()->tunisia()->for($tenant)->create([
            'id' => '00000000-0000-4000-8000-000000000302',
        ]);
        $author = User::factory()->for($tenant)->create();
        $firstTemplate = $this->template($tenant, $firstCompany, $author, [
            'next_due_date' => '2026-07-13',
            'start_date' => '2026-01-13',
            'lead_days' => 0,
        ]);
        $secondTemplate = $this->template($tenant, $secondCompany, $author, [
            'next_due_date' => '2026-07-13',
            'start_date' => '2026-01-13',
            'lead_days' => 0,
        ]);

        $this->admin($otherTenant, '00000000-0000-4000-8000-000000000001', UserStatus::Active);
        $this->admin($tenant, '00000000-0000-4000-8000-000000000003', UserStatus::Inactive);
        $fallback = $this->admin($tenant, '00000000-0000-4000-8000-000000000004', UserStatus::Active);
        $this->admin($tenant, '00000000-0000-4000-8000-000000000005', UserStatus::Active);

        DB::statement('PRAGMA defer_foreign_keys = ON');
        $missingAuthor = Str::uuid()->toString();
        $firstTemplate->update(['created_by' => $missingAuthor]);

        try {
            $exit = Artisan::call('expenses:generate-recurring');
            self::assertSame(0, $exit, Artisan::output());

            $firstExpense = ExpenseMetadata::query()
                ->where('recurrence_template_id', $firstTemplate->id)
                ->sole()
                ->document;
            $secondExpense = ExpenseMetadata::query()
                ->where('recurrence_template_id', $secondTemplate->id)
                ->sole()
                ->document;
            self::assertSame($tenant->id, $firstExpense->tenant_id);
            self::assertSame($firstCompany->id, $firstExpense->company_id);
            self::assertSame($tenant->id, $secondExpense->tenant_id);
            self::assertSame($secondCompany->id, $secondExpense->company_id);
            self::assertNotSame($firstExpense->company_id, $secondExpense->company_id);
        } finally {
            $firstTemplate->update(['created_by' => $fallback->id]);
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }
    }

    public function test_lead_gate_uses_company_today_at_utc_auckland_boundary_and_shared_maximum_prefilter(): void
    {
        Carbon::setTestNow('2026-07-13 12:30:00 UTC');

        $tenant = Tenant::factory()->create();
        $auckland = Company::factory()->for($tenant)->create(['timezone' => 'Pacific/Auckland']);
        $utc = Company::factory()->for($tenant)->create(['timezone' => 'UTC']);
        $author = User::factory()->for($tenant)->create();
        $aucklandTemplate = $this->template($tenant, $auckland, $author, [
            'start_date' => '2026-09-12',
            'next_due_date' => '2026-09-12',
            'lead_days' => ExpenseRecurrenceTemplate::MAX_LEAD_DAYS,
        ]);
        $utcTemplate = $this->template($tenant, $utc, $author, [
            'start_date' => '2026-09-12',
            'next_due_date' => '2026-09-12',
            'lead_days' => ExpenseRecurrenceTemplate::MAX_LEAD_DAYS,
        ]);

        $exit = Artisan::call('expenses:generate-recurring');
        self::assertSame(0, $exit, Artisan::output());

        self::assertDatabaseHas('expense_metadata', ['recurrence_template_id' => $aucklandTemplate->id]);
        self::assertDatabaseMissing('expense_metadata', ['recurrence_template_id' => $utcTemplate->id]);

        $source = (string) file_get_contents(app_path('Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php'));
        self::assertStringContainsString('ExpenseRecurrenceTemplate::MAX_LEAD_DAYS', $source);
        self::assertStringNotContainsString('addDays(60)', $source);
    }

    public function test_company_failure_isolated_later_company_generates_and_command_returns_failure(): void
    {
        Carbon::setTestNow('2026-07-13 08:00:00 UTC');

        $tenant = Tenant::factory()->create();
        $failingCompany = Company::factory()->tunisia()->for($tenant)->create([
            'id' => '00000000-0000-4000-8000-000000000401',
        ]);
        $laterCompany = Company::factory()->tunisia()->for($tenant)->create([
            'id' => '00000000-0000-4000-8000-000000000402',
        ]);
        $author = User::factory()->for($tenant)->create();
        $this->template($tenant, $failingCompany, $author, [
            'amount' => '10.000',
            'vat_amount' => '20.000',
            'vat_rate' => '200.00',
            'next_due_date' => '2026-07-13',
            'start_date' => '2026-01-13',
            'lead_days' => 0,
        ]);
        $later = $this->template($tenant, $laterCompany, $author, [
            'next_due_date' => '2026-07-13',
            'start_date' => '2026-01-13',
            'lead_days' => 0,
        ]);

        self::assertSame(1, Artisan::call('expenses:generate-recurring'));
        self::assertDatabaseHas('expense_metadata', ['recurrence_template_id' => $later->id]);
        self::assertSame(1, Document::query()->where('company_id', $laterCompany->id)->count());
        self::assertSame(0, Document::query()->where('company_id', $failingCompany->id)->count());
    }

    public function test_command_is_scheduled_in_process_at_0530_and_never_uses_company_context(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        $schedule = Artisan::output();
        self::assertStringContainsString('expenses:generate-recurring', $schedule);
        self::assertMatchesRegularExpression('/30\s+5\s+\*\s+\*\s+\*/', $schedule);

        $commandSource = (string) file_get_contents(app_path('Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php'));
        self::assertStringContainsString('parent::__construct($companyContext)', $commandSource);
        self::assertStringNotContainsString('$this->companyContext', $commandSource);
        self::assertStringNotContainsString('setCompanyId(', $commandSource);
        self::assertStringNotContainsString('requireCompany', $commandSource);

        $consoleSource = (string) file_get_contents(base_path('routes/console.php'));
        $start = strpos($consoleSource, "Schedule::command('expenses:generate-recurring')");
        self::assertNotFalse($start);
        $scheduleBlock = substr($consoleSource, $start, 180);
        self::assertStringContainsString("dailyAt('05:30')", $scheduleBlock);
        self::assertStringContainsString('withoutOverlapping()', $scheduleBlock);
        self::assertStringNotContainsString('runInBackground()', $scheduleBlock);
    }

    /** @param array<string, mixed> $overrides */
    private function template(Tenant $tenant, Company $company, User $author, array $overrides): ExpenseRecurrenceTemplate
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('expenses.post', 'sanctum');

        return ExpenseRecurrenceTemplate::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => "Recurring {$company->id}",
            'amount' => '100.000',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-01-13',
            'next_due_date' => '2026-07-13',
            'lead_days' => 0,
            'status' => RecurrenceStatus::Active,
            'created_by' => $author->id,
        ], $overrides));
    }

    private function recipient(Tenant $tenant, Company $company, string $id, bool $activeMembership): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate('expenses.post', 'sanctum');

        $user = User::factory()->for($tenant)->create(['id' => $id]);
        $user->givePermissionTo('expenses.post');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'manager',
            'status' => $activeMembership ? 'active' : 'suspended',
        ]);

        return $user;
    }

    private function admin(Tenant $tenant, string $id, UserStatus $status): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();
        Role::findOrCreate('admin', 'sanctum');

        $user = User::factory()->for($tenant)->create(['id' => $id, 'status' => $status]);
        $user->assignRole('admin');

        return $user;
    }
}
