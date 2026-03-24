<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Events\ProductCreated;
use App\Modules\Product\Domain\Events\ProductDeleted;
use App\Modules\Product\Domain\Events\ProductUpdated;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_product_created_event_is_dispatched_on_store(): void
    {
        Event::fake([ProductCreated::class]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'type' => 'part',
                'sale_price' => '19.99',
            ])
            ->assertStatus(201);

        Event::assertDispatched(ProductCreated::class, function (ProductCreated $event): bool {
            return $event->name === 'Test Product'
                && $event->sku === 'SKU-001'
                && $event->type === 'part'
                && $event->salePrice === '19.99'
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->getEventName() === 'product.created';
        });
    }

    public function test_product_updated_event_is_dispatched_on_update(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'sku' => 'SKU-002',
            'type' => 'part',
            'sale_price' => '10.00',
        ]);

        Event::fake([ProductUpdated::class]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'name' => 'Updated Name',
                'sale_price' => '15.00',
            ])
            ->assertStatus(200);

        Event::assertDispatched(ProductUpdated::class, function (ProductUpdated $event) use ($product): bool {
            return $event->productId === $product->id
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && is_array($event->changes)
                && $event->getEventName() === 'product.updated';
        });
    }

    public function test_product_deleted_event_is_dispatched_on_destroy(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'To Delete',
            'sku' => 'SKU-003',
            'type' => 'part',
            'sale_price' => '5.00',
        ]);

        Event::fake([ProductDeleted::class]);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertStatus(204);

        Event::assertDispatched(ProductDeleted::class, function (ProductDeleted $event) use ($product): bool {
            return $event->productId === $product->id
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->getEventName() === 'product.deleted';
        });
    }
}
