<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\RepositoryOutflowInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class RepositoryOutflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_outflow_decrements_repository_balance(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]);
        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '100.000',
            'type' => RepositoryType::CashRegister,
        ]);

        app(RepositoryOutflowInterface::class)->applyOutflow(
            $repo->id, $user->tenant_id, $company->id, '30.000', 'TND'
        );

        $this->assertSame('70.000', $repo->fresh()->balance);
    }

    public function test_apply_outflow_fires_repository_balance_changed_event(): void
    {
        Event::fake([RepositoryBalanceChanged::class]);

        [$user, $company] = $this->makeUserWithPermissions([]);
        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '200.000',
            'type' => RepositoryType::CashRegister,
        ]);

        app(RepositoryOutflowInterface::class)->applyOutflow(
            $repo->id, $user->tenant_id, $company->id, '50.000', 'TND'
        );

        Event::assertDispatched(RepositoryBalanceChanged::class, function (RepositoryBalanceChanged $event) use ($repo, $company, $user): bool {
            return $event->repositoryId === $repo->id
                && $event->tenantId === $user->tenant_id
                && $event->companyId === $company->id
                && $event->previousBalance === '200.000'
                && $event->newBalance === '150.000'
                && $event->changeAmount === '50.000'
                && $event->currency === 'TND';
        });
    }

    public function test_apply_outflow_scopes_to_tenant_and_company(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]);
        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '100.000',
            'type' => RepositoryType::CashRegister,
        ]);

        $this->expectException(ModelNotFoundException::class);

        app(RepositoryOutflowInterface::class)->applyOutflow(
            $repo->id, 'wrong-tenant-id', $company->id, '10.000', 'TND'
        );
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
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
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
}
