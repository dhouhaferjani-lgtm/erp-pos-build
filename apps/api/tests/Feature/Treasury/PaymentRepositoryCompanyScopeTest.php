<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\CompanyPaymentRepositoryProvisionerInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PaymentRepositoryCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_show_are_scoped_to_the_active_company(): void
    {
        $tenant = Tenant::create([
            'name' => 'Repository Scope Tenant',
            'slug' => 'repository-scope-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Repository Scope Admin',
            'email' => 'repository-scope@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        $companyA = Company::factory()->for($tenant)->create();
        $companyB = Company::factory()->for($tenant)->create();

        foreach ([$companyA, $companyB] as $company) {
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => 'admin',
            ]);

            $location = Location::factory()->for($company)->create([
                'code' => 'MAIN',
                'type' => LocationType::Shop,
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => true,
            ]);
            Account::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => '531',
                'type' => AccountType::Asset,
                'system_purpose' => SystemAccountPurpose::Cash,
            ]);

            app(CompanyPaymentRepositoryProvisionerInterface::class)
                ->provisionForCompany($tenant->id, $company->id, $location->id);
        }

        $companyARepositories = PaymentRepository::query()
            ->where('company_id', $companyA->id)
            ->orderBy('code')
            ->get();
        $companyBRepositories = PaymentRepository::query()
            ->where('company_id', $companyB->id)
            ->orderBy('code')
            ->get();

        self::assertSame(['CASH-01', 'SAFE-01'], $companyARepositories->pluck('code')->all());
        self::assertSame(['CASH-01', 'SAFE-01'], $companyBRepositories->pluck('code')->all());

        $companyAResponse = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $companyA->id)
            ->getJson('/api/v1/payment-repositories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        self::assertEqualsCanonicalizing(
            $companyARepositories->pluck('id')->all(),
            array_column($companyAResponse->json('data'), 'id'),
        );

        $companyBResponse = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $companyB->id)
            ->getJson('/api/v1/payment-repositories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        self::assertEqualsCanonicalizing(
            $companyBRepositories->pluck('id')->all(),
            array_column($companyBResponse->json('data'), 'id'),
        );

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $companyB->id)
            ->getJson('/api/v1/payment-repositories/'.$companyARepositories->firstOrFail()->id)
            ->assertNotFound();
    }
}
