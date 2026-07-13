<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentsData;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\Reports\UpcomingPaymentsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UpcomingPaymentsRecurringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-13 08:00:00 UTC');
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->for($this->tenant)->create([
            'currency' => 'TND',
            'timezone' => 'UTC',
        ]);
        $this->author = User::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('expenses.post', 'sanctum');
        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_active_template_cursor_inside_window_appears_as_projected_money_out(): void
    {
        $this->template([
            'name' => 'Monthly office rent',
            'vendor_name' => 'Landlord SARL',
            'amount' => '250.125',
            'next_due_date' => '2026-07-20',
        ]);

        $report = $this->report();

        self::assertCount(1, $report->out);
        self::assertSame('Monthly office rent', $report->out[0]->document_number);
        self::assertSame('Monthly office rent', $report->out[0]->partner_name);
        self::assertSame('2026-07-20', $report->out[0]->due_date);
        self::assertSame('250.125', $report->out[0]->balance_due);
        self::assertSame('recurrence_projection', $report->out[0]->source);
        self::assertSame('projected', $report->out[0]->certainty);
        self::assertSame('250.125', $report->total_out);
    }

    public function test_generation_moves_period_from_projection_to_materialized_draft_without_gap_or_double_count(): void
    {
        $template = $this->template([
            'name' => 'Warehouse lease',
            'amount' => '900.500',
            'next_due_date' => '2026-07-20',
            'lead_days' => 7,
        ]);

        $before = $this->report();
        self::assertCount(1, $before->out);
        self::assertSame('recurrence_projection', $before->out[0]->source);
        self::assertSame('900.500', $before->total_out);

        self::assertSame(0, Artisan::call('expenses:generate-recurring'), Artisan::output());

        $draft = Document::query()->where('type', DocumentType::Expense)->sole();
        self::assertSame(DocumentStatus::Draft, $draft->status);
        self::assertSame('2026-07-20', $draft->document_date->toDateString());
        self::assertSame($template->id, $draft->expenseMetadata?->recurrence_template_id);

        $after = $this->report();
        self::assertCount(1, $after->out);
        self::assertSame('document', $after->out[0]->source);
        self::assertSame('2026-07-20', $after->out[0]->due_date);
        self::assertSame('900.500', $after->out[0]->balance_due);
        self::assertSame('900.500', $after->total_out);
        self::assertSame('2026-08-20', $template->fresh()?->next_due_date->toDateString());
    }

    public function test_posting_generated_unpaid_draft_moves_period_to_existing_open_expense_feed(): void
    {
        $this->template([
            'name' => 'Security service',
            'amount' => '80.000',
            'next_due_date' => '2026-07-20',
            'lead_days' => 7,
        ]);
        self::assertSame(0, Artisan::call('expenses:generate-recurring'), Artisan::output());

        $draft = Document::query()->where('type', DocumentType::Expense)->sole();
        $beforePost = $this->report();
        self::assertCount(1, $beforePost->out);
        self::assertSame('document', $beforePost->out[0]->source);
        $posted = app(ExpenseService::class)->post($draft, $this->author);
        self::assertSame(DocumentStatus::Posted, $posted->status);
        self::assertFalse($posted->expenseMetadata?->is_paid);

        $report = $this->report();

        self::assertCount(1, $report->out);
        self::assertSame('document', $report->out[0]->source);
        self::assertSame($posted->document_number, $report->out[0]->document_number);
        self::assertSame('2026-07-20', $report->out[0]->due_date);
        self::assertSame('80.000', $report->total_out);
    }

    public function test_deleting_generated_draft_removes_advanced_period_without_reprojecting_it(): void
    {
        $template = $this->template([
            'amount' => '45.000',
            'next_due_date' => '2026-07-20',
            'lead_days' => 7,
        ]);
        self::assertSame(0, Artisan::call('expenses:generate-recurring'), Artisan::output());

        $beforeDelete = $this->report();
        self::assertCount(1, $beforeDelete->out);
        self::assertSame('document', $beforeDelete->out[0]->source);
        Document::query()->where('type', DocumentType::Expense)->sole()->delete();

        $report = $this->report();

        self::assertCount(0, $report->out);
        self::assertSame('0.000', $report->total_out);
        self::assertSame('2026-08-20', $template->fresh()?->next_due_date->toDateString());
    }

    public function test_projected_amounts_remain_strings_and_sum_with_company_currency_scale(): void
    {
        $this->template([
            'name' => 'First fractional projection',
            'amount' => '0.001',
            'next_due_date' => '2026-07-20',
        ]);
        $this->template([
            'name' => 'Second fractional projection',
            'amount' => '0.002',
            'next_due_date' => '2026-07-21',
        ]);

        $report = $this->report();

        self::assertCount(2, $report->out);
        self::assertSame('0.001', $report->out[0]->balance_due);
        self::assertSame('0.002', $report->out[1]->balance_due);
        self::assertSame('0.003', $report->total_out);
        self::assertSame('-0.003', $report->net);
    }

    public function test_projection_iterates_cursor_and_includes_the_occurrence_on_end_date(): void
    {
        $this->template([
            'name' => 'Inclusive bounded recurrence',
            'amount' => '12.000',
            'start_date' => '2026-07-13',
            'next_due_date' => '2026-07-13',
            'end_date' => '2026-08-13',
        ]);

        $report = app(UpcomingPaymentsService::class)->generate($this->company->id, 60);

        self::assertCount(2, $report->out);
        self::assertSame('2026-07-13', $report->out[0]->due_date);
        self::assertSame('2026-08-13', $report->out[1]->due_date);
        self::assertSame('24.000', $report->total_out);
    }

    /** @param array<string, mixed> $overrides */
    private function template(array $overrides): ExpenseRecurrenceTemplate
    {
        return ExpenseRecurrenceTemplate::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Recurring expense',
            'vendor_name' => 'Recurring vendor',
            'amount' => '100.000',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-01-20',
            'next_due_date' => '2026-07-20',
            'lead_days' => 0,
            'status' => RecurrenceStatus::Active,
            'created_by' => $this->author->id,
        ], $overrides));
    }

    private function report(): UpcomingPaymentsData
    {
        return app(UpcomingPaymentsService::class)->generate($this->company->id, 30);
    }
}
