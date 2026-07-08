<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\Enums\PriceEntryMode;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class CompanyDiscountPolicySettingsTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->for($this->tenant)->create();
        $this->admin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_company_discount_policy_settings_persist(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}", [
                'default_max_discount_percent' => '12.50',
                'discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission->value,
                'price_entry_mode' => PriceEntryMode::Ttc->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.default_max_discount_percent', '12.50')
            ->assertJsonPath('data.discount_floor_mode', 'WarnRequiresPermission')
            ->assertJsonPath('data.price_entry_mode', 'Ttc');
    }

    public function test_company_default_max_discount_rejects_more_than_two_decimals(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}", [
                'default_max_discount_percent' => '12.505',
            ]);

        $this->assertApiValidationErrors($response, ['default_max_discount_percent']);
    }
}
