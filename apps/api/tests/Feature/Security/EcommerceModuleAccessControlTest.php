<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Channel\ExampleTestAdapter;
use Tests\TestCase;

/**
 * Feature test for RequireModule middleware protecting the Channel module's
 * tenant-facing routes behind the Ecommerce extra.
 *
 * Verifies end-to-end that:
 * 1. Tenants without the Ecommerce extra get 403 on /api/v1/channels routes
 * 2. Tenants with the Ecommerce extra enabled retain full access
 * 3. The webhook ingress (/api/v1/webhooks/channels) is NOT module-gated,
 *    because external platforms call it and authenticate via channel
 *    signature, not via tenant auth.
 */
final class EcommerceModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $gatedTenant;

    private Company $gatedCompany;

    private User $gatedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Retail tenant WITHOUT the Ecommerce extra.
        $this->gatedTenant = Tenant::create([
            'name' => 'No Ecommerce Tenant',
            'slug' => 'no-ecommerce-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => 'retail',
            'enabled_extras' => [],
        ]);

        $this->gatedCompany = $this->makeCompany($this->gatedTenant->id, 'Gated Company');
        $this->gatedUser = $this->makeUser($this->gatedTenant->id, 'gated@example.com', $this->gatedCompany);
    }

    public function test_user_without_ecommerce_module_cannot_list_channels(): void
    {
        $response = $this->actingAs($this->gatedUser)->getJson('/api/v1/channels');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Ecommerce' is not enabled for this business type",
        ]);
    }

    public function test_user_without_ecommerce_module_cannot_access_aggregate_orders(): void
    {
        $response = $this->actingAs($this->gatedUser)->getJson('/api/v1/channels/orders');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Ecommerce' is not enabled for this business type",
        ]);
    }

    public function test_user_with_ecommerce_extra_can_list_channels(): void
    {
        [, , $user] = $this->makeEcommerceTenant();

        $response = $this->actingAs($user)->getJson('/api/v1/channels');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data.channels'));
    }

    public function test_user_with_ecommerce_extra_can_access_aggregate_orders(): void
    {
        [, , $user] = $this->makeEcommerceTenant();

        $response = $this->actingAs($user)->getJson('/api/v1/channels/orders');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
        $this->assertIsArray($response->json('meta'));
    }

    public function test_webhook_ingress_is_not_gated_by_ecommerce_module(): void
    {
        Queue::fake();

        // Channel belongs to the tenant WITHOUT the Ecommerce extra: the
        // external platform must still be able to deliver webhooks.
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);

        $channel = Channel::create([
            'company_id' => $this->gatedCompany->id,
            'name' => 'Webhook channel',
            'adapter_type' => 'example_test',
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Pending,
            'metadata' => [],
        ]);

        $response = $this->postJson("/api/v1/webhooks/channels/{$channel->id}", [
            'external_order_id' => 'ORDER-GATE-1',
            'total' => '10.000',
        ], [
            'X-Channel-Timestamp' => (string) time(),
        ]);

        $response->assertAccepted();
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function makeEcommerceTenant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Ecommerce Tenant',
            'slug' => 'ecommerce-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => 'retail',
            'enabled_extras' => ['Ecommerce'],
        ]);

        $company = $this->makeCompany($tenant->id, 'Ecommerce Company');
        $user = $this->makeUser($tenant->id, 'ecommerce@example.com', $company);

        return [$tenant, $company, $user];
    }

    private function makeCompany(string $tenantId, string $name): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX-'.substr(md5($name), 0, 8),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeUser(string $tenantId, string $email, Company $company): User
    {
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        return $user;
    }
}
