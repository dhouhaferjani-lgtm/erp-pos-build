<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifies that the WorkOrder detail + list endpoints redact monetary fields
 * when the caller lacks `work-orders.view_financials`. Admin/accountant roles
 * that hold the permission see all numbers; operators/technicians do not.
 */
final class FinancialRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([
            'work-orders.view',
            'work-orders.view_financials',
            'work-orders.create',
            'work-orders.update',
            'work-orders.approve',
            'work-orders.assign',
            'work-orders.transition',
            'work-orders.cancel',
            'work-orders.complete',
        ] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);
        $user->givePermissionTo($permissions);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_show_returns_totals_when_user_has_view_financials_permission(): void
    {
        $this->actingAsUserWithPermissions(['work-orders.view', 'work-orders.view_financials']);

        $wo = WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'estimated_grand_total' => '150.000',
            'actual_grand_total' => '145.000',
        ]);

        $response = $this->getJson("/api/v1/workshop/work-orders/{$wo->id}");

        $response->assertOk();
        $response->assertJsonPath('data.estimated_totals.grand_total', '150.000');
        $response->assertJsonPath('data.actual_totals.grand_total', '145.000');
    }

    public function test_show_redacts_totals_without_view_financials_permission(): void
    {
        $this->actingAsUserWithPermissions(['work-orders.view']);

        $wo = WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'estimated_grand_total' => '150.000',
        ]);

        $response = $this->getJson("/api/v1/workshop/work-orders/{$wo->id}");

        $response->assertOk();
        $response->assertJsonPath('data.estimated_totals', null);
        $response->assertJsonPath('data.actual_totals', null);
    }

    public function test_index_redacts_grand_totals_without_view_financials_permission(): void
    {
        $this->actingAsUserWithPermissions(['work-orders.view']);

        WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'estimated_grand_total' => '150.000',
            'actual_grand_total' => '120.000',
        ]);

        $response = $this->getJson('/api/v1/workshop/work-orders');

        $response->assertOk();
        $response->assertJsonPath('data.0.estimated_grand_total', null);
        $response->assertJsonPath('data.0.actual_grand_total', null);
    }

    public function test_index_shows_grand_totals_with_view_financials_permission(): void
    {
        $this->actingAsUserWithPermissions(['work-orders.view', 'work-orders.view_financials']);

        WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'estimated_grand_total' => '150.000',
            'actual_grand_total' => '120.000',
        ]);

        $response = $this->getJson('/api/v1/workshop/work-orders');

        $response->assertOk();
        $response->assertJsonPath('data.0.estimated_grand_total', '150.000');
        $response->assertJsonPath('data.0.actual_grand_total', '120.000');
    }

    public function test_show_without_view_permission_returns_403(): void
    {
        $this->actingAsUserWithPermissions([]);

        $wo = WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->getJson("/api/v1/workshop/work-orders/{$wo->id}");
        $response->assertStatus(403);
    }
}
