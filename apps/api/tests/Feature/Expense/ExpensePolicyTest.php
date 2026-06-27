<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
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
        $expense = Document::factory()->create([
            'type' => DocumentType::Expense,
            'company_id' => $company->id,
            'tenant_id' => $user->tenant_id,
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('view', $expense));
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

        // Set company_id as an in-memory attribute so the policy's company-scope
        // guard ($document->company_id !== $user->company_id) works correctly
        // without an HTTP request / CompanyContextMiddleware populating it.
        $user->company_id = $company->id;

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
