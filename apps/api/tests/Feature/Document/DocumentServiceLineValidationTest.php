<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Tests that document lines properly validate the mutual exclusivity
 * of product_id and service_id.
 */
class DocumentServiceLineValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Product $product;

    private Service $service;

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

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'PRD-001',
            'sale_price' => '100.00',
        ]);

        $this->service = Service::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SVC-001',
            'name' => 'Test Service',
            'base_price' => '50.00',
        ]);
    }

    public function test_can_create_document_with_product_line(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'description' => 'Test Product',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $response->assertCreated();
    }

    public function test_can_create_document_with_service_line(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'service_id' => $this->service->id,
                        'description' => 'Test Service',
                        'quantity' => '1.00',
                        'unit_price' => '50.00',
                    ],
                ],
            ]);

        $response->assertCreated();
    }

    public function test_cannot_set_both_product_id_and_service_id_on_same_line(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'service_id' => $this->service->id,
                        'description' => 'Both set',
                        'quantity' => '1.00',
                        'unit_price' => '100.00',
                    ],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_service_id_must_exist_in_services_table(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'service_id' => '00000000-0000-0000-0000-000000000000',
                        'description' => 'Non-existent service',
                        'quantity' => '1.00',
                        'unit_price' => '50.00',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.service_id']);
    }

    public function test_can_create_document_with_mixed_product_and_service_lines(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'description' => 'Product Line',
                        'quantity' => '2.00',
                        'unit_price' => '100.00',
                    ],
                    [
                        'service_id' => $this->service->id,
                        'description' => 'Service Line',
                        'quantity' => '1.00',
                        'unit_price' => '50.00',
                    ],
                ],
            ]);

        $response->assertCreated();
    }
}
