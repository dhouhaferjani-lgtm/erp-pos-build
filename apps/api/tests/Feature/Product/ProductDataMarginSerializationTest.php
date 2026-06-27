<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductDataMarginSerializationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant  = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create([
            'default_target_margin'  => '30.00',
            'default_minimum_margin' => '15.00',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id'    => $this->user->id,
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
        $this->actingAs($this->user, 'sanctum');
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_product_show_includes_pricing_mode_and_effective_margins(): void
    {
        $product = Product::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pricing_mode', 'manual')
            ->assertJsonPath('data.effective_margins.target_margin', '30.00')
            ->assertJsonPath('data.effective_margins.target_source', 'company');
    }
}
