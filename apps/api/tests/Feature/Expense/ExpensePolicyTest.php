<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Policies\DocumentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpensePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_resolves_the_real_document_policy(): void
    {
        $this->assertInstanceOf(DocumentPolicy::class, Gate::getPolicyFor(Document::class));
    }

    public function test_user_with_expenses_view_can_view_expense_document(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.view']);

        // Set the active company through CompanyContext — the real path used
        // by CompanyContextMiddleware in production. No in-memory hack.
        app(CompanyContext::class)->setCompanyId($company->id);

        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $expense));
    }

    public function test_user_without_expenses_view_cannot_view_expense_document(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]); // no expense perms

        // Set the active company through CompanyContext — even with correct
        // company context the policy must deny when permission is absent.
        app(CompanyContext::class)->setCompanyId($company->id);

        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $expense));
    }

    /**
     * HTTP-level test: proves that CompanyContext → DocumentPolicy::delete works
     * end-to-end for DELETE /api/v1/expenses/{id}.
     *
     * Uses DELETE (not GET show) to avoid a pre-existing 'attachments' eager-load
     * issue in the show controller; the Gate path is identical.
     */
    public function test_http_expense_delete_returns_200_for_permitted_user_and_403_for_unpermitted(): void
    {
        [$permitted, $company] = $this->makeUserWithPermissions(['expenses.delete']);
        [$denied] = $this->makeUserWithPermissionsInCompany([], $company);

        // Let the factory supply defaults; only override what must be controlled.
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $permitted->tenant_id,
        ]);

        // Set CompanyContext the same way a real request does (middleware sets
        // it; here we set it directly since the expense routes don't wire
        // CompanyContextMiddleware).
        app(CompanyContext::class)->setCompanyId($company->id);

        // Denied user first — Gate denies before deletion, so the expense
        // remains and the permitted user can still delete it.
        $responseForbidden = $this->actingAs($denied, 'sanctum')
            ->deleteJson('/api/v1/expenses/'.$expense->id);

        $responseForbidden->assertStatus(403);

        // Permitted user → 200 (expense is deleted)
        $responseOk = $this->actingAs($permitted, 'sanctum')
            ->deleteJson('/api/v1/expenses/'.$expense->id);

        $responseOk->assertStatus(200);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }

    /**
     * Create an additional user with the given permissions inside an existing company.
     *
     * @param  list<string>  $permissions
     * @return array{0: User}
     */
    private function makeUserWithPermissionsInCompany(array $permissions, Company $company): array
    {
        $user = User::create([
            'tenant_id' => $company->tenant_id,
            'name' => 'Denied User',
            'email' => 'denied_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'viewer',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user];
    }
}
