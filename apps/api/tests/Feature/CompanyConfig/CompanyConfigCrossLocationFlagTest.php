<?php

declare(strict_types=1);

namespace Tests\Feature\CompanyConfig;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompanyConfigCrossLocationFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_config_exposes_cross_location_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'allow_cross_location_stock_view' => true,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Primary active membership so $company resolves in the controller
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'is_primary' => true,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.allow_cross_location_stock_view', true);
    }

    public function test_company_config_flag_defaults_to_false(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'allow_cross_location_stock_view' => false,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'is_primary' => true,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.allow_cross_location_stock_view', false);
    }
}
