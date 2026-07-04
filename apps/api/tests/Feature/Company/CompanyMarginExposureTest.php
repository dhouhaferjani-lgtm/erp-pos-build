<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CompanyMarginExposureTest extends TestCase
{
    use RefreshDatabase;
    use AssertsApiValidation;

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

    public function test_company_payload_exposes_default_margins(): void
    {
        $company = Company::factory()->for($this->tenant)->create([
            'default_target_margin'  => '30',
            'default_minimum_margin' => '15',
        ]);
        UserCompanyMembership::create([
            'user_id'    => $this->user->id,
            'company_id' => $company->id,
            'role'       => 'admin',
        ]);

        $res = $this->getJson("/api/v1/companies/{$company->id}");

        $res->assertOk()
            ->assertJsonPath('data.default_target_margin', '30.00')
            ->assertJsonPath('data.default_minimum_margin', '15.00');
    }

    public function test_update_persists_default_margins(): void
    {
        $res = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'default_target_margin'  => '35.00',
            'default_minimum_margin' => '12.00',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.default_target_margin', '35.00')
            ->assertJsonPath('data.default_minimum_margin', '12.00');

        $this->assertDatabaseHas('companies', [
            'id'                     => $this->company->id,
            'default_target_margin'  => '35.00',
        ]);
    }

    public function test_update_rejects_null_default_target_margin(): void
    {
        $res = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'default_target_margin' => null,
        ]);
        $this->assertApiValidationErrors($res, ['default_target_margin']);
    }

    public function test_update_rejects_inverted_default_band(): void
    {
        $res = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'default_target_margin'  => '20.00',
            'default_minimum_margin' => '40.00',
        ]);

        $this->assertApiValidationErrors($res, ['default_minimum_margin']);
    }
}
