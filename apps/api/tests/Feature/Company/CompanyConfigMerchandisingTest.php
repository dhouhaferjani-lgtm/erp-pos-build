<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies that a parapharmacy tenant has the Merchandising module
 * bundled in its all_enabled_modules via GET /api/v1/company/config.
 */
final class CompanyConfigMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    public function test_parapharmacy_tenant_has_merchandising_in_all_enabled_modules(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'parapharmacy',
            'enabled_extras' => json_encode([]),
        ]);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/company/config');

        $response->assertOk();

        $allEnabledModules = $response->json('data.all_enabled_modules');

        $this->assertContains(
            'Merchandising',
            $allEnabledModules,
            'Parapharmacy vertical must bundle Merchandising in all_enabled_modules'
        );
    }

    public function test_parapharmacy_config_returns_parapharmacy_vertical(): void
    {
        $tenant = Tenant::factory()->create([
            'vertical' => 'parapharmacy',
            'enabled_extras' => json_encode([]),
        ]);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/company/config');

        $response->assertOk()
            ->assertJsonPath('data.vertical', 'parapharmacy');
    }
}
