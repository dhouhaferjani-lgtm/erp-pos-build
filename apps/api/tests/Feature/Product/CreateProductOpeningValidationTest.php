<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Validates the opening-balance fields introduced in the IziPOS product editor:
 *   - opening_qty:       nullable, numeric, max 4 decimal places
 *   - opening_unit_cost: nullable, numeric, max 3 decimal places
 *   - Conditional: cost REQUIRED when qty > 0; cost FORBIDDEN when qty absent/0.
 *
 * Rule 19 (precision contract): both fields are received as strings and validated
 * against a regex ceiling — never parsed as floats inside FormRequest logic.
 */
class CreateProductOpeningValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Opening Validation Tenant',
            'slug' => 'opening-validation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Validation Company',
            'legal_name' => 'Opening Validation Company LLC',
            'tax_id' => 'TAX-OV-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Validation User',
            'email' => 'user@opening-validation.example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * Minimal valid product payload, with the given overrides merged in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validProductPayload(array $overrides = []): array
    {
        static $counter = 0;
        $counter++;

        return array_merge([
            'name' => "Opening Test Product {$counter}",
            'sku' => "OV-SKU-{$counter}",
        ], $overrides);
    }

    public function test_opening_cost_required_when_qty_positive(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', $this->validProductPayload(['opening_qty' => '10']));

        $this->assertApiValidationErrors($response, ['opening_unit_cost']);
    }

    public function test_opening_cost_forbidden_without_positive_qty(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', $this->validProductPayload(['opening_unit_cost' => '5.000']));

        $this->assertApiValidationErrors($response, ['opening_unit_cost']);
    }

    public function test_opening_qty_over_scale_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', $this->validProductPayload([
                'opening_qty' => '10.00001',
                'opening_unit_cost' => '5.000',
            ]));

        $this->assertApiValidationErrors($response, ['opening_qty']);
    }
}
