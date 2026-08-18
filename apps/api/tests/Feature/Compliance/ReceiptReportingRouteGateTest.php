<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReceiptReportingRouteGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $accountant;

    private User $settingsViewer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $tenant->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->accountant = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->accountant->assignRole('accountant');

        $this->settingsViewer = User::factory()->create(['tenant_id' => $tenant->id]);
        Permission::findOrCreate('settings.view', 'sanctum');
        Role::findOrCreate('settings-only', 'sanctum')->syncPermissions(['settings.view']);
        $this->settingsViewer->assignRole('settings-only');

        foreach ([$this->accountant, $this->settingsViewer] as $user) {
            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $this->company->id,
                'role' => $user->is($this->accountant) ? 'accountant' : 'viewer',
            ]);
        }
    }

    public function test_seeded_accountant_reaches_compliance_export_and_fraud_reads(): void
    {
        $this->actingAs($this->accountant, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/compliance/nf525/export-jet', [
                'from' => '2026-01-01',
                'to' => '2026-01-31',
            ])
            ->assertOk();
        $this->postJson('/api/v1/compliance/nf525/verify-chains')->assertOk();
        $this->getJson('/api/v1/compliance/nf525/reprint-log')->assertOk();
        $this->getJson('/api/v1/fraud-settings')->assertOk();
        $this->getJson('/api/v1/fraud-alerts')->assertOk();
    }

    public function test_settings_view_alone_cannot_reach_compliance_or_fraud_reads(): void
    {
        $this->actingAs($this->settingsViewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);

        $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ])->assertForbidden();
        $this->postJson('/api/v1/compliance/nf525/verify-chains')->assertForbidden();
        $this->getJson('/api/v1/compliance/nf525/reprint-log')->assertForbidden();
        $this->getJson('/api/v1/fraud-settings')->assertForbidden();
        $this->getJson('/api/v1/fraud-alerts')->assertForbidden();
    }
}
