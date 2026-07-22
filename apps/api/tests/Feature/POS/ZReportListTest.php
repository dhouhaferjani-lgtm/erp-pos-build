<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ZReportListTest extends TestCase
{
    use RefreshDatabase;

    public function test_unfiltered_list_returns_reports_with_location_and_terminal_names(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user->givePermissionTo('pos.view_reports');
        app(CompanyContext::class)->setCompanyId($company->id);

        foreach (['Store A', 'Store B'] as $index => $name) {
            $location = Location::factory()->create(['company_id' => $company->id, 'name' => $name]);
            $terminal = Terminal::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'name' => 'Terminal '.($index + 1),
            ]);
            $shift = Shift::query()->create([
                'terminal_id' => $terminal->id,
                'cashier_id' => $user->id,
                'shift_number' => $index + 1,
                'opened_at' => now()->subHours(3),
                'opening_cash' => '0.000',
                'status' => 'CLOSED',
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);
            ZReport::query()->create([
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'z_number' => $index + 1,
                'fiscal_hash' => hash('sha256', $name),
                'report_data' => ['gross_sales' => '10.000'],
                'generated_by' => $user->id,
                'generated_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/pos/reports/z');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.location_name', 'Store A');
        $response->assertJsonPath('data.1.location_name', 'Store B');
    }
}
