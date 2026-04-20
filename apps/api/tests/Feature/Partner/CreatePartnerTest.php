<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreatePartnerTest extends TestCase
{
    use AssertsApiValidation;
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
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'type' => 'customer',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_type_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_type_must_be_valid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
                'type' => 'invalid',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_email_format_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
                'type' => 'customer',
                'email' => 'invalid-email',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_vat_number_format_for_france(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
                'type' => 'customer',
                'country_code' => 'FR',
                'vat_number' => 'INVALID123',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_vat_number_format_for_tunisia(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => 'INVALID',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_duplicate_vat_number_detection(): void
    {
        // First, create a partner with a VAT number
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'First Partner',
                'type' => 'customer',
                'country_code' => 'FR',
                'vat_number' => 'FR12345678901',
            ])
            ->assertCreated();

        // Try to create another partner with the same VAT number
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Second Partner',
                'type' => 'customer',
                'country_code' => 'FR',
                'vat_number' => 'FR12345678901',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_successful_creation_returns_201(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'ACME Corporation',
                'type' => 'customer',
                'email' => 'contact@acme.com',
                'phone' => '+33123456789',
                'country_code' => 'FR',
                'vat_number' => 'FR12345678901',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'ACME Corporation')
            ->assertJsonPath('data.type', 'customer')
            ->assertJsonPath('data.email', 'contact@acme.com')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'type',
                    'email',
                    'phone',
                    'country_code',
                    'vat_number',
                    'created_at',
                ],
                'meta',
            ]);

        $this->assertDatabaseHas('partners', [
            'name' => 'ACME Corporation',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_can_create_customer_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Customer Partner',
                'type' => 'customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'customer');
    }

    public function test_can_create_supplier_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Supplier Partner',
                'type' => 'supplier',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'supplier');
    }

    public function test_can_create_both_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Both Partner',
                'type' => 'both',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'both');
    }

    public function test_unauthenticated_user_cannot_create_partner(): void
    {
        $response = $this->postJson('/api/v1/partners', [
            'name' => 'Test Partner',
            'type' => 'customer',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_create_partner(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Test Partner',
                'type' => 'customer',
            ]);

        $response->assertForbidden();
    }
}
