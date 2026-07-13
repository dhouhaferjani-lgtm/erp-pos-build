<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpenseRecurrenceCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $siblingCompany;

    private User $manager;

    private ExpenseCategory $category;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-13 09:00:00');

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->for($this->tenant)->create();
        $this->siblingCompany = Company::factory()->tunisia()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = $this->userWithRole('manager', $this->company, 'manager@example.test');
        $this->category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Rent',
        ]);
        $this->partner = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->paymentRepository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_can_create_list_show_update_pause_resume_and_delete_a_template(): void
    {
        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Tunis office rent')
            ->assertJsonPath('data.next_due_date', '2026-07-31')
            ->assertJsonPath('data.status', 'active');

        $id = $created->json('data.id');
        self::assertIsString($id);

        $this->as($this->manager)->getJson('/api/v1/expense-recurrences')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->as($this->manager)->getJson("/api/v1/expense-recurrences/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'name' => 'Annual office rent',
            'frequency' => 'yearly',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Annual office rent')
            ->assertJsonPath('data.next_due_date', '2027-01-31');

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'frequency' => 'monthly',
            'start_date' => '2026-08-15',
        ])->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-08-15');

        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        ExpenseRecurrenceTemplate::query()->whereKey($id)->update([
            'start_date' => '2026-01-31',
            'frequency' => RecurrenceFrequency::Monthly,
            'next_due_date' => '2026-03-31',
        ]);

        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.next_due_date', '2026-07-31');

        $this->as($this->manager)->deleteJson("/api/v1/expense-recurrences/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('expense_recurrence_templates', ['id' => $id]);
    }

    public function test_queries_are_tenant_and_company_scoped_without_cross_company_find_leakage(): void
    {
        $sibling = $this->templateFor($this->tenant, $this->siblingCompany, $this->manager);
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->tunisia()->for($otherTenant)->create();
        $otherUser = User::factory()->for($otherTenant)->create();
        $foreign = $this->templateFor($otherTenant, $otherCompany, $otherUser);
        $own = $this->templateFor($this->tenant, $this->company, $this->manager);

        $response = $this->as($this->manager)->getJson('/api/v1/expense-recurrences')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        self::assertContains($own->id, $ids);
        self::assertNotContains($sibling->id, $ids);
        self::assertNotContains($foreign->id, $ids);

        $this->as($this->manager)->getJson("/api/v1/expense-recurrences/{$sibling->id}")
            ->assertNotFound();
        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$sibling->id}", ['name' => 'Leaked'])
            ->assertNotFound();
        $this->as($this->manager)->deleteJson("/api/v1/expense-recurrences/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_all_default_foreign_keys_reject_sibling_company_ids(): void
    {
        $foreignFixtures = [
            'expense_category_id' => ExpenseCategory::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->siblingCompany->id,
                'name' => 'Foreign category',
            ])->id,
            'partner_id' => Partner::factory()->supplier()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->siblingCompany->id,
            ])->id,
            'payment_method_id' => PaymentMethod::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->siblingCompany->id,
            ])->id,
            'payment_repository_id' => PaymentRepository::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->siblingCompany->id,
            ])->id,
        ];

        foreach ($foreignFixtures as $field => $id) {
            $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
                ...$this->validPayload(),
                $field => $id,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors($field, 'error.errors');
        }
    }

    public function test_request_rejects_invalid_money_percent_dates_enums_and_lead_days(): void
    {
        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'amount' => '1.0001',
            'vat_amount' => '0.0001',
            'vat_rate' => '19.001',
            'vat_deductible_percent' => '100.01',
            'frequency' => 'weekly',
            'status' => 'cancelled',
            'lead_days' => 61,
            'start_date' => '2026-08-01',
            'end_date' => '2026-07-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount',
                'vat_amount',
                'vat_rate',
                'vat_deductible_percent',
                'frequency',
                'status',
                'lead_days',
                'end_date',
            ], 'error.errors');
    }

    public function test_update_enforces_end_date_against_the_merged_existing_start_date(): void
    {
        $template = $this->templateFor($this->tenant, $this->company, $this->manager);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$template->id}", [
            'end_date' => '2026-12-31',
        ])->assertOk()
            ->assertJsonPath('data.end_date', '2026-12-31');

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$template->id}", [
            'start_date' => '2027-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('end_date', 'error.errors');
    }

    public function test_seeded_role_grants_match_the_normative_recurrence_and_export_matrix(): void
    {
        $fullPermissions = [
            'expense-recurrences.view',
            'expense-recurrences.create',
            'expense-recurrences.update',
            'expense-recurrences.delete',
            'expenses.export',
        ];

        foreach (['admin', 'manager', 'accountant'] as $roleName) {
            $role = Role::findByName($roleName, 'sanctum');
            foreach ($fullPermissions as $permission) {
                self::assertTrue($role->hasPermissionTo($permission, 'sanctum'), "{$roleName} missing {$permission}");
            }
        }

        foreach (['cashier', 'operator', 'viewer'] as $roleName) {
            $role = Role::findByName($roleName, 'sanctum');
            self::assertTrue($role->hasPermissionTo('expense-recurrences.view', 'sanctum'));
            foreach (array_slice($fullPermissions, 1) as $permission) {
                self::assertFalse($role->hasPermissionTo($permission, 'sanctum'), "{$roleName} unexpectedly has {$permission}");
            }
        }
    }

    public function test_cashier_operator_and_viewer_can_view_but_cannot_mutate_templates(): void
    {
        $template = $this->templateFor($this->tenant, $this->company, $this->manager);

        foreach (['cashier', 'operator', 'viewer'] as $role) {
            $user = $this->userWithRole($role, $this->company, "{$role}@example.test");

            $this->as($user)->getJson('/api/v1/expense-recurrences')->assertOk();
            $this->as($user)->getJson("/api/v1/expense-recurrences/{$template->id}")->assertOk();
            $this->as($user)->postJson('/api/v1/expense-recurrences', $this->validPayload())->assertForbidden();
            $this->as($user)->putJson("/api/v1/expense-recurrences/{$template->id}", ['name' => 'Denied'])->assertForbidden();
            $this->as($user)->deleteJson("/api/v1/expense-recurrences/{$template->id}")->assertForbidden();
            $this->as($user)->postJson("/api/v1/expense-recurrences/{$template->id}/pause")->assertForbidden();
            $this->as($user)->postJson("/api/v1/expense-recurrences/{$template->id}/resume")->assertForbidden();
        }
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'name' => 'Tunis office rent',
            'expense_category_id' => $this->category->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
            'vendor_name' => 'Landlord SARL',
            'amount' => '1190.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
            'vat_amount' => '190.000',
            'notes' => 'Monthly rent',
            'frequency' => 'monthly',
            'start_date' => '2026-01-31',
            'end_date' => '2027-12-31',
            'lead_days' => 5,
        ];
    }

    private function userWithRole(string $role, Company $company, string $email): User
    {
        $user = User::factory()->create([
            'tenant_id' => $company->tenant_id,
            'email' => $email,
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function templateFor(Tenant $tenant, Company $company, User $creator): ExpenseRecurrenceTemplate
    {
        return ExpenseRecurrenceTemplate::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => "Template {$company->id}",
            'amount' => '100.000',
            'frequency' => RecurrenceFrequency::Monthly,
            'start_date' => '2026-01-31',
            'next_due_date' => '2026-07-31',
            'status' => RecurrenceStatus::Active,
            'created_by' => $creator->id,
        ]);
    }

    private function as(User $user): self
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }
}
