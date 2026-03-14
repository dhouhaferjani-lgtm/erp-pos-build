<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\POS\Domain\Floor;
use App\Modules\POS\Domain\Table;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TableManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    // ─── Floor CRUD ──────────────────────────────────────────────────────────────

    public function test_create_floor_succeeds(): void
    {
        $response = $this->postJson('/api/v1/pos/floors', [
            'name' => 'Main Floor',
            'position' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => 'Main Floor',
            'position' => 1,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('pos_floors', [
            'company_id' => $this->company->id,
            'name' => 'Main Floor',
        ]);
    }

    public function test_list_floors_returns_floors_with_tables(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Terrace',
            'position' => 0,
            'is_active' => true,
        ]);

        Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'floor_id' => $floor->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->getJson('/api/v1/pos/floors');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Terrace');
        $response->assertJsonCount(1, 'data.0.tables');
    }

    public function test_update_floor_succeeds(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Old Name',
            'position' => 0,
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/v1/pos/floors/{$floor->id}", [
            'name' => 'New Name',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'name' => 'New Name',
            'is_active' => false,
        ]);
    }

    public function test_delete_floor_succeeds_when_no_tables(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Empty Floor',
            'position' => 0,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/v1/pos/floors/{$floor->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('pos_floors', ['id' => $floor->id]);
    }

    public function test_delete_floor_fails_when_has_tables(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Floor With Tables',
            'position' => 0,
            'is_active' => true,
        ]);

        Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'floor_id' => $floor->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->deleteJson("/api/v1/pos/floors/{$floor->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('pos_floors', ['id' => $floor->id]);
    }

    // ─── Table CRUD ──────────────────────────────────────────────────────────────

    public function test_create_table_succeeds(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main',
            'position' => 0,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/pos/tables', [
            'floor_id' => $floor->id,
            'table_number' => 'T01',
            'label' => 'Window Seat',
            'seats' => 6,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'table_number' => 'T01',
            'label' => 'Window Seat',
            'seats' => 6,
            'status' => 'available',
        ]);
    }

    public function test_list_tables_with_floor_filter(): void
    {
        $floor1 = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Floor 1',
            'position' => 0,
            'is_active' => true,
        ]);

        $floor2 = Floor::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Floor 2',
            'position' => 1,
            'is_active' => true,
        ]);

        Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'floor_id' => $floor1->id,
            'table_number' => 'A1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'floor_id' => $floor2->id,
            'table_number' => 'B1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->getJson("/api/v1/pos/tables?floor_id={$floor1->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.table_number', 'A1');
    }

    public function test_update_table_succeeds(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->patchJson("/api/v1/pos/tables/{$table->id}", [
            'label' => 'VIP Corner',
            'seats' => 8,
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'label' => 'VIP Corner',
            'seats' => 8,
        ]);
    }

    public function test_delete_table_succeeds_when_available(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->deleteJson("/api/v1/pos/tables/{$table->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('pos_tables', ['id' => $table->id]);
    }

    public function test_delete_table_fails_when_occupied(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
        ]);

        $response = $this->deleteJson("/api/v1/pos/tables/{$table->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('pos_tables', ['id' => $table->id]);
    }

    // ─── Table Status Operations ─────────────────────────────────────────────────

    public function test_set_table_status_succeeds(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Available,
        ]);

        $response = $this->postJson("/api/v1/pos/tables/{$table->id}/status", [
            'status' => 'reserved',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'reserved']);
    }

    public function test_release_table_succeeds(): void
    {
        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'table_number' => 'T1',
            'seats' => 4,
            'status' => TableStatus::Occupied,
            'current_order_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        $response = $this->postJson("/api/v1/pos/tables/{$table->id}/release");

        $response->assertOk();
        $response->assertJsonFragment([
            'status' => 'available',
            'current_order_id' => null,
        ]);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_tables', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.manage_tables');
        $this->user->givePermissionTo('pos.operate_terminal');
    }
}
