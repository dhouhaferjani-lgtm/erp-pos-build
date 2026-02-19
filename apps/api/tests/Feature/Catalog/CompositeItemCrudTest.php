<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompositeItemCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'composite-items.view',
            'composite-items.create',
            'composite-items.update',
            'composite-items.delete',
            'composite-items.manage-recipes',
            'modifier-groups.view',
            'modifier-groups.manage',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_create_a_composite_item(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code' => 'CAPPUCCINO',
            'name' => 'Cappuccino',
            'base_price' => 5.50,
            'vertical_type' => 'fnb',
            'production_type' => 'made_to_order',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'CAPPUCCINO');
        $response->assertJsonPath('data.name', 'Cappuccino');
        $response->assertJsonPath('data.vertical_type', 'fnb');

        $this->assertDatabaseHas('composite_items', [
            'code' => 'CAPPUCCINO',
            'company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function it_can_list_composite_items(): void
    {
        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-1',
            'name' => 'Item One',
            'base_price' => 10.00,
        ]);

        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-2',
            'name' => 'Item Two',
            'base_price' => 20.00,
        ]);

        $response = $this->getJson('/api/v1/composite-items');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_can_show_a_composite_item_with_relations(): void
    {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-SHOW',
            'name' => 'Show Item',
            'base_price' => 15.00,
        ]);

        $response = $this->getJson("/api/v1/composite-items/{$item->id}");

        $response->assertOk();
        $response->assertJsonPath('data.code', 'ITEM-SHOW');
    }

    /** @test */
    public function it_can_update_a_composite_item(): void
    {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-UPDATE',
            'name' => 'Update Item',
            'base_price' => 10.00,
        ]);

        $response = $this->patchJson("/api/v1/composite-items/{$item->id}", [
            'name' => 'Updated Name',
            'base_price' => 12.50,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated Name');
        $response->assertJsonPath('data.base_price', '12.5000');
    }

    /** @test */
    public function it_can_delete_a_composite_item(): void
    {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-DELETE',
            'name' => 'Delete Item',
            'base_price' => 10.00,
        ]);

        $response = $this->deleteJson("/api/v1/composite-items/{$item->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('composite_items', ['id' => $item->id]);
    }

    /** @test */
    public function it_can_duplicate_a_composite_item(): void
    {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-DUP',
            'name' => 'Duplicate Item',
            'base_price' => 10.00,
        ]);

        $response = $this->postJson("/api/v1/composite-items/{$item->id}/duplicate");

        $response->assertStatus(201);
        $this->assertStringContainsString('Duplicate Item (Copy)', $response->json('data.name'));
        $this->assertDatabaseCount('composite_items', 2);
    }

    /** @test */
    public function it_enforces_unique_code_per_company(): void
    {
        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'UNIQUE-CODE',
            'name' => 'First Item',
            'base_price' => 10.00,
        ]);

        $response = $this->postJson('/api/v1/composite-items', [
            'code' => 'UNIQUE-CODE',
            'name' => 'Second Item',
            'base_price' => 20.00,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_scopes_items_to_current_company(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create();

        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'OTHER-ITEM',
            'name' => 'Other Company Item',
            'base_price' => 10.00,
        ]);

        $response = $this->getJson('/api/v1/composite-items');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /** @test */
    public function it_can_search_composite_items(): void
    {
        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CAP-001',
            'name' => 'Cappuccino',
            'base_price' => 5.00,
        ]);

        CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LAT-001',
            'name' => 'Latte',
            'base_price' => 4.50,
        ]);

        $response = $this->getJson('/api/v1/composite-items?search=Capp');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Cappuccino');
    }
}
