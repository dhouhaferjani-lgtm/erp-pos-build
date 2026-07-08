<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class CategoryDiscountCapValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->for($this->tenant)->create();
        $this->manager->assignRole('manager');

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_category_max_discount_percent_rejects_more_than_two_decimals(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/categories', [
                'name' => 'Fluids',
                'max_discount_percent' => '7.505',
            ]);

        $this->assertApiValidationErrors($response, ['max_discount_percent']);
    }

    public function test_category_max_discount_percent_persists_as_string(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/categories', [
                'name' => 'Fluids',
                'max_discount_percent' => '7.50',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.max_discount_percent', '7.50');
    }
}
