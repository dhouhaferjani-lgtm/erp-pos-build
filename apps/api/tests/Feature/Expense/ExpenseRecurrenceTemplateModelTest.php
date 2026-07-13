<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExpenseRecurrenceTemplateModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_the_complete_recurrence_template_contract(): void
    {
        $this->assertTrue(Schema::hasTable('expense_recurrence_templates'));

        foreach ([
            'id',
            'tenant_id',
            'company_id',
            'name',
            'expense_category_id',
            'partner_id',
            'payment_method_id',
            'payment_repository_id',
            'vendor_name',
            'amount',
            'vat_rate',
            'vat_deductible_percent',
            'vat_amount',
            'notes',
            'frequency',
            'start_date',
            'end_date',
            'lead_days',
            'status',
            'next_due_date',
            'created_by',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('expense_recurrence_templates', $column),
                "expense_recurrence_templates missing column {$column}",
            );
        }

        $this->assertTrue(Schema::hasColumn('expense_metadata', 'recurrence_template_id'));
    }

    public function test_model_mass_assigns_fields_and_casts_values_without_losing_precision(): void
    {
        $template = new ExpenseRecurrenceTemplate([
            'tenant_id' => 'tenant-id',
            'company_id' => 'company-id',
            'name' => 'Tunis office rent',
            'expense_category_id' => 'category-id',
            'partner_id' => 'partner-id',
            'payment_method_id' => 'method-id',
            'payment_repository_id' => 'repository-id',
            'vendor_name' => 'Landlord SARL',
            'amount' => '119.123',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
            'vat_amount' => '19.123',
            'notes' => 'Monthly rent',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-07-31',
            'end_date' => '2027-06-30',
            'lead_days' => 5,
            'status' => RecurrenceStatus::Paused,
            'next_due_date' => '2026-08-31',
            'created_by' => 'user-id',
        ]);

        $this->assertSame('tenant-id', $template->tenant_id);
        $this->assertSame('company-id', $template->company_id);
        $this->assertSame('Tunis office rent', $template->name);
        $this->assertSame('category-id', $template->expense_category_id);
        $this->assertSame('partner-id', $template->partner_id);
        $this->assertSame('method-id', $template->payment_method_id);
        $this->assertSame('repository-id', $template->payment_repository_id);
        $this->assertSame('Landlord SARL', $template->vendor_name);
        $this->assertSame('119.123', $template->amount);
        $this->assertSame('19.00', $template->vat_rate);
        $this->assertSame('80.00', $template->vat_deductible_percent);
        $this->assertSame('19.123', $template->vat_amount);
        $this->assertSame('Monthly rent', $template->notes);
        $this->assertSame(RecurrenceFrequency::Monthly, $template->frequency);
        $this->assertInstanceOf(Carbon::class, $template->start_date);
        $this->assertSame('2026-07-31', $template->start_date->toDateString());
        $this->assertInstanceOf(Carbon::class, $template->end_date);
        $this->assertSame('2027-06-30', $template->end_date->toDateString());
        $this->assertSame(5, $template->lead_days);
        $this->assertSame(RecurrenceStatus::Paused, $template->status);
        $this->assertInstanceOf(Carbon::class, $template->next_due_date);
        $this->assertSame('2026-08-31', $template->next_due_date->toDateString());
        $this->assertSame('user-id', $template->created_by);
    }

    public function test_database_defaults_to_active_with_a_three_day_lead(): void
    {
        [$tenant, $company, $user] = $this->scopeFixtures();

        $template = ExpenseRecurrenceTemplate::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Internet subscription',
            'amount' => '90.000',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-08-01',
            'next_due_date' => '2026-08-01',
            'created_by' => $user->id,
        ])->refresh();

        $this->assertSame(3, $template->lead_days);
        $this->assertSame(RecurrenceStatus::Active, $template->status);
    }

    public function test_default_foreign_keys_are_nullable_and_set_null_when_targets_are_deleted(): void
    {
        [$tenant, $company, $user] = $this->scopeFixtures();

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Rent',
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

        $template = ExpenseRecurrenceTemplate::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Tunis office rent',
            'expense_category_id' => $category->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'payment_repository_id' => $repository->id,
            'amount' => '1190.000',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-07-01',
            'next_due_date' => '2026-08-01',
            'created_by' => $user->id,
        ]);

        $category->delete();
        $partner->forceDelete();
        $method->delete();
        $repository->delete();

        $template->refresh();

        $this->assertNull($template->expense_category_id);
        $this->assertNull($template->partner_id);
        $this->assertNull($template->payment_method_id);
        $this->assertNull($template->payment_repository_id);
    }

    public function test_metadata_recurrence_link_is_nullable_and_set_null_on_template_delete(): void
    {
        [$tenant, $company, $user] = $this->scopeFixtures();
        $template = ExpenseRecurrenceTemplate::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Insurance',
            'amount' => '300.000',
            'frequency' => RecurrenceFrequency::Yearly,
            'start_date' => '2026-09-15',
            'next_due_date' => '2026-09-15',
            'created_by' => $user->id,
        ]);
        $metadata = ExpenseMetadata::create([
            'document_id' => Document::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ])->id,
        ]);

        $metadata->update(['recurrence_template_id' => $template->id]);
        $metadata->refresh();

        $this->assertSame($template->id, $metadata->recurrence_template_id);

        $template->delete();

        $this->assertDatabaseHas('expense_metadata', [
            'id' => $metadata->id,
            'recurrence_template_id' => null,
        ]);
    }

    /** @return array{Tenant, Company, User} */
    private function scopeFixtures(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $company, $user];
    }
}
