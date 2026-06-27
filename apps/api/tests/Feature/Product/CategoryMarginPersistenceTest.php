<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CategoryMarginPersistenceTest extends TestCase
{
    use RefreshDatabase, AssertsApiValidation;

    protected Tenant $tenant;
    protected Company $company;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant  = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id'    => $this->user->id,
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
        $this->actingAs($this->user);
    }

    public function test_persists_category_overrides(): void
    {
        $res = $this->postJson('/api/v1/categories', [
            'name'                    => 'Oils',
            'target_margin_override'  => '40.00',
            'minimum_margin_override' => '20.00',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('categories', [
            'name'                   => 'Oils',
            'target_margin_override' => '40.00',
        ]);
    }

    public function test_rejects_inverted_band(): void
    {
        $res = $this->postJson('/api/v1/categories', [
            'name'                    => 'Bad',
            'target_margin_override'  => '20.00',
            'minimum_margin_override' => '40.00',
        ]);

        $this->assertApiValidationErrors($res, ['minimum_margin_override']);
    }

    public function test_rejects_three_decimals(): void
    {
        $res = $this->postJson('/api/v1/categories', [
            'name'                   => 'Prec',
            'target_margin_override' => '40.123',
        ]);

        $this->assertApiValidationErrors($res, ['target_margin_override']);
    }
}
