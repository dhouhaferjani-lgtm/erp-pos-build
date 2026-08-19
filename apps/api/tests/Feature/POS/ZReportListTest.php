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
        $fixture = $this->seedFixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson('/api/v1/pos/reports/z');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.location_name', 'Store A');
        $response->assertJsonPath('data.1.location_name', 'Store B');
    }

    public function test_terminal_and_location_filters_narrow_the_list(): void
    {
        $fixture = $this->seedFixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson(
            '/api/v1/pos/reports/z?terminal_id='.$fixture['terminalA']->id.'&location_ids[]='.$fixture['locationA']->id,
        );

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.location_name', 'Store A');
        $response->assertJsonPath('data.0.terminal_id', $fixture['terminalA']->id);
    }

    public function test_location_filter_executes_the_terminal_location_predicate(): void
    {
        $fixture = $this->seedFixture();

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson(
            '/api/v1/pos/reports/z?location_ids[]='.$fixture['locationB']->id,
        );

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.location_name', 'Store B');
    }

    public function test_out_of_scope_location_is_rejected(): void
    {
        $fixture = $this->seedFixture();
        $fixture['membership']->update(['allowed_location_ids' => [$fixture['locationA']->id]]);

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson(
            '/api/v1/pos/reports/z?location_ids[]='.$fixture['locationB']->id,
        );

        $response->assertForbidden();
    }

    public function test_restricted_membership_without_location_param_returns_only_allowed_reports(): void
    {
        $fixture = $this->seedFixture();
        $fixture['membership']->update(['allowed_location_ids' => [$fixture['locationA']->id]]);

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson('/api/v1/pos/reports/z');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.location_name', 'Store A');
    }

    public function test_zero_allowed_locations_returns_empty_list(): void
    {
        $fixture = $this->seedFixture();
        $fixture['membership']->update(['allowed_location_ids' => []]);

        $response = $this->actingAs($fixture['user'], 'sanctum')->getJson('/api/v1/pos/reports/z');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * @param  list<string>|null  $allowedLocationIds
     * @return array{user: User, membership: UserCompanyMembership, locationA: Location, locationB: Location, terminalA: Terminal, terminalB: Terminal}
     */
    private function seedFixture(?array $allowedLocationIds = null): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $membership = UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
            'allowed_location_ids' => $allowedLocationIds,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user->givePermissionTo('pos.view_reports');
        app(CompanyContext::class)->setCompanyId($company->id);

        $locationA = Location::factory()->create(['company_id' => $company->id, 'name' => 'Store A']);
        $locationB = Location::factory()->create(['company_id' => $company->id, 'name' => 'Store B']);
        $terminalA = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $locationA->id,
            'name' => 'Terminal 1',
        ]);
        $terminalB = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $locationB->id,
            'name' => 'Terminal 2',
        ]);

        foreach ([$terminalA, $terminalB] as $index => $terminal) {
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
                'fiscal_hash' => hash('sha256', (string) $index),
                'report_data' => ['gross_sales' => '10.000'],
                'generated_by' => $user->id,
                'generated_at' => now()->subMinutes($index),
            ]);
        }

        return compact('user', 'membership', 'locationA', 'locationB', 'terminalA', 'terminalB');
    }
}
