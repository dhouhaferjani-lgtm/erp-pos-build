<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

    public function test_create_enforces_the_company_currency_grid_and_complete_vat_tuple(): void
    {
        $this->company->update(['currency' => 'EUR']);

        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'amount' => '119.001',
            'vat_amount' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount', 'error.errors');

        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'amount' => '119.00',
            'vat_amount' => '19.001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vat_amount', 'error.errors');

        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'amount' => '119.00',
            'vat_amount' => '19.00',
            'vat_rate' => null,
            'vat_deductible_percent' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'vat_rate',
                'vat_deductible_percent',
            ], 'error.errors');

        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'amount' => '19.00',
            'vat_amount' => '19.00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vat_amount', 'error.errors');

        self::assertSame(0, ExpenseRecurrenceTemplate::query()->count());
    }

    public function test_create_without_vat_normalizes_the_entire_tuple_to_null(): void
    {
        $payload = $this->validPayload();
        unset($payload['vat_amount']);

        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', $payload)
            ->assertCreated()
            ->assertJsonPath('data.vat_amount', null)
            ->assertJsonPath('data.vat_rate', null)
            ->assertJsonPath('data.vat_deductible_percent', null);

        $id = $created->json('data.id');
        self::assertIsString($id);
        $template = ExpenseRecurrenceTemplate::query()->findOrFail($id);
        self::assertNull($template->vat_amount);
        self::assertNull($template->vat_rate);
        self::assertNull($template->vat_deductible_percent);
    }

    public function test_update_validates_merged_vat_values_and_explicit_null_clears_the_tuple(): void
    {
        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', $this->validPayload())
            ->assertCreated();
        $id = $created->json('data.id');
        self::assertIsString($id);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'amount' => '100.000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vat_amount', 'error.errors');

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'vat_rate' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vat_rate', 'error.errors');

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'vat_amount' => null,
        ])->assertOk()
            ->assertJsonPath('data.vat_amount', null)
            ->assertJsonPath('data.vat_rate', null)
            ->assertJsonPath('data.vat_deductible_percent', null);
    }

    public function test_partial_updates_keep_vatless_and_legacy_zero_templates_fully_normalized(): void
    {
        $payload = $this->validPayload();
        unset(
            $payload['vat_amount'],
            $payload['vat_rate'],
            $payload['vat_deductible_percent'],
        );
        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', $payload)
            ->assertCreated();
        $id = $created->json('data.id');
        self::assertIsString($id);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'vat_rate' => '7.50',
        ])->assertOk()
            ->assertJsonPath('data.vat_amount', null)
            ->assertJsonPath('data.vat_rate', null)
            ->assertJsonPath('data.vat_deductible_percent', null);

        $template = ExpenseRecurrenceTemplate::query()->findOrFail($id);
        $template->update([
            'vat_amount' => '0.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '100.00',
        ]);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$id}", [
            'notes' => 'Normalize legacy zero VAT',
        ])->assertOk()
            ->assertJsonPath('data.vat_amount', null)
            ->assertJsonPath('data.vat_rate', null)
            ->assertJsonPath('data.vat_deductible_percent', null);

        $template->refresh();
        self::assertNull($template->vat_amount);
        self::assertNull($template->vat_rate);
        self::assertNull($template->vat_deductible_percent);
    }

    public function test_invalid_vat_is_rejected_before_persistence_and_cannot_poison_generation(): void
    {
        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'name' => 'Poison template',
            'amount' => '10.000',
            'vat_amount' => '20.000',
            'start_date' => '2026-07-13',
            'lead_days' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('vat_amount', 'error.errors');

        $validPayload = $this->validPayload();
        unset(
            $validPayload['vat_amount'],
            $validPayload['vat_rate'],
            $validPayload['vat_deductible_percent'],
        );
        $validPayload['name'] = 'Safe later template';
        $validPayload['start_date'] = '2026-07-13';
        $validPayload['lead_days'] = 0;
        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', $validPayload)
            ->assertCreated();
        $validId = $created->json('data.id');
        self::assertIsString($validId);

        self::assertSame(1, ExpenseRecurrenceTemplate::query()->count());
        self::assertSame(0, Artisan::call('expenses:generate-recurring'), Artisan::output());
        self::assertSame(1, Document::query()->count());
        self::assertSame(
            $validId,
            ExpenseMetadata::query()->whereNotNull('recurrence_template_id')->sole()->recurrence_template_id,
        );
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

        $template->refresh()->update([
            'start_date' => '2026-06-01',
            'end_date' => null,
            'next_due_date' => '2026-08-01',
            'status' => RecurrenceStatus::Active,
        ]);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$template->id}", [
            'end_date' => '2026-05-31',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('end_date', 'error.errors');
    }

    public function test_cursor_beyond_inclusive_end_date_ends_create_and_update_lifecycles(): void
    {
        $created = $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'frequency' => 'yearly',
            'start_date' => '2025-01-01',
            'end_date' => '2026-12-31',
        ])->assertCreated()
            ->assertJsonPath('data.next_due_date', '2027-01-01')
            ->assertJsonPath('data.status', 'ended');

        $createdId = $created->json('data.id');
        self::assertIsString($createdId);

        $this->as($this->manager)->postJson('/api/v1/expense-recurrences', [
            ...$this->validPayload(),
            'frequency' => 'yearly',
            'start_date' => '2025-01-01',
            'end_date' => '2027-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.next_due_date', '2027-01-01')
            ->assertJsonPath('data.status', 'active');

        $shortened = $this->templateFor($this->tenant, $this->company, $this->manager);
        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$shortened->id}", [
            'end_date' => '2026-07-30',
        ])->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-07-31')
            ->assertJsonPath('data.status', 'ended');

        $recomputed = $this->templateFor($this->tenant, $this->company, $this->manager);
        $recomputed->update(['end_date' => '2026-11-30']);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$recomputed->id}", [
            'frequency' => 'yearly',
            'start_date' => '2025-12-31',
        ])->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-12-31')
            ->assertJsonPath('data.status', 'ended');
    }

    public function test_resume_and_generic_paused_to_active_roll_forward_and_end_expired_templates(): void
    {
        $dedicated = $this->templateFor($this->tenant, $this->company, $this->manager);
        $dedicated->update([
            'status' => RecurrenceStatus::Paused,
            'next_due_date' => '2026-03-31',
            'end_date' => '2026-06-30',
        ]);

        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$dedicated->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-07-31')
            ->assertJsonPath('data.status', 'ended');

        $generic = $this->templateFor($this->tenant, $this->company, $this->manager);
        $generic->update([
            'status' => RecurrenceStatus::Paused,
            'next_due_date' => '2026-03-31',
            'end_date' => '2026-06-30',
        ]);

        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$generic->id}", [
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-07-31')
            ->assertJsonPath('data.status', 'ended');
    }

    public function test_dedicated_lifecycle_endpoints_enforce_source_states_and_ended_is_terminal(): void
    {
        $active = $this->templateFor($this->tenant, $this->company, $this->manager);
        $activeCursor = $active->next_due_date->toDateString();
        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$active->id}/resume")
            ->assertUnprocessable();
        self::assertSame(RecurrenceStatus::Active, $active->refresh()->status);
        self::assertSame($activeCursor, $active->next_due_date->toDateString());

        $paused = $this->templateFor($this->tenant, $this->company, $this->manager);
        $paused->update([
            'status' => RecurrenceStatus::Paused,
            'next_due_date' => '2026-03-31',
        ]);
        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$paused->id}/pause")
            ->assertUnprocessable();
        self::assertSame(RecurrenceStatus::Paused, $paused->refresh()->status);
        self::assertSame('2026-03-31', $paused->next_due_date->toDateString());

        $ended = $this->templateFor($this->tenant, $this->company, $this->manager);
        $ended->update([
            'status' => RecurrenceStatus::Ended,
            'next_due_date' => '2026-03-31',
        ]);

        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$ended->id}/pause")
            ->assertUnprocessable();
        $this->as($this->manager)->postJson("/api/v1/expense-recurrences/{$ended->id}/resume")
            ->assertUnprocessable();
        $this->as($this->manager)->putJson("/api/v1/expense-recurrences/{$ended->id}", [
            'status' => 'active',
        ])->assertUnprocessable();

        $ended->refresh();
        self::assertSame(RecurrenceStatus::Ended, $ended->status);
        self::assertSame('2026-03-31', $ended->next_due_date->toDateString());
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
