<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Enums\ChannelOrderStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChannelOrdersAggregateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Channel $channelA;

    private Channel $channelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            // /api/v1/channels/* is gated on the Ecommerce extra (T8).
            'enabled_extras' => ['Ecommerce'],
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

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->channelA = $this->makeChannel($this->company->id, 'Shopify Store');
        $this->channelB = $this->makeChannel($this->company->id, 'WooCommerce Store');
    }

    public function test_aggregates_orders_across_all_company_channels_with_channel_info(): void
    {
        $orderA = $this->makeOrder($this->channelA, 'EXT-A1', ChannelOrderStatus::Pending, '2026-06-01 10:00:00');
        $orderB = $this->makeOrder($this->channelB, 'EXT-B1', ChannelOrderStatus::Pending, '2026-06-02 10:00:00');

        $response = $this->actingAs($this->user)->getJson('/api/v1/channels/orders');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        // Newest received_at first.
        $this->assertSame($orderB->id, $response->json('data.0.id'));
        $this->assertSame($orderA->id, $response->json('data.1.id'));
        // Channel relation is included so the frontend can show the channel name.
        $this->assertSame('WooCommerce Store', $response->json('data.0.channel.name'));
        $this->assertSame('Shopify Store', $response->json('data.1.channel.name'));
    }

    public function test_excludes_orders_from_other_companies(): void
    {
        $this->makeOrder($this->channelA, 'EXT-A1', ChannelOrderStatus::Pending, '2026-06-01 10:00:00');

        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $otherChannel = $this->makeChannel($otherCompany->id, 'Other Store');
        $foreignOrder = $this->makeOrder($otherChannel, 'EXT-X1', ChannelOrderStatus::Pending, '2026-06-03 10:00:00');

        $response = $this->actingAs($this->user)->getJson('/api/v1/channels/orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertNotContains(
            $foreignOrder->id,
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_status_filter_returns_only_matching_orders(): void
    {
        $this->makeOrder($this->channelA, 'EXT-A1', ChannelOrderStatus::Pending, '2026-06-01 10:00:00');
        $processed = $this->makeOrder($this->channelB, 'EXT-B1', ChannelOrderStatus::Processed, '2026-06-02 10:00:00');

        $response = $this->actingAs($this->user)->getJson('/api/v1/channels/orders?status=processed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($processed->id, $response->json('data.0.id'));
    }

    public function test_invalid_status_is_rejected_with_422(): void
    {
        // bootstrap/app.php renders ValidationException as error.code/error.errors.
        $this->actingAs($this->user)
            ->getJson('/api/v1/channels/orders?status=not-a-status')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['status']]]);
    }

    public function test_channel_id_filter_returns_only_that_channels_orders(): void
    {
        $orderA = $this->makeOrder($this->channelA, 'EXT-A1', ChannelOrderStatus::Pending, '2026-06-01 10:00:00');
        $this->makeOrder($this->channelB, 'EXT-B1', ChannelOrderStatus::Pending, '2026-06-02 10:00:00');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/channels/orders?channel_id={$this->channelA->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($orderA->id, $response->json('data.0.id'));
    }

    public function test_non_uuid_channel_id_is_rejected_with_422_not_500(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/channels/orders?channel_id=not-a-uuid')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['channel_id']]]);
    }

    public function test_pagination_meta_is_present(): void
    {
        $this->makeOrder($this->channelA, 'EXT-A1', ChannelOrderStatus::Pending, '2026-06-01 10:00:00');
        $this->makeOrder($this->channelB, 'EXT-B1', ChannelOrderStatus::Pending, '2026-06-02 10:00:00');

        $response = $this->actingAs($this->user)->getJson('/api/v1/channels/orders?per_page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_orders_literal_is_not_captured_by_the_per_channel_id_route(): void
    {
        // If 'orders' were captured as {id} by GET channels/{id}/orders-style
        // resolution, findChannel('orders') would 404 (or 500 on PG uuid cast).
        // The aggregate route must win and return the paginated shape.
        $response = $this->actingAs($this->user)->getJson('/api/v1/channels/orders');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertIsArray($response->json('meta'));
    }

    private function makeChannel(string $companyId, string $name): Channel
    {
        return Channel::create([
            'company_id' => $companyId,
            'name' => $name,
            'adapter_type' => 'example_test',
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Pending,
            'metadata' => [],
        ]);
    }

    private function makeOrder(
        Channel $channel,
        string $externalOrderId,
        ChannelOrderStatus $status,
        string $receivedAt,
    ): ChannelOrder {
        return ChannelOrder::create([
            'channel_id' => $channel->id,
            'external_order_id' => $externalOrderId,
            'received_at' => $receivedAt,
            'status' => $status,
            'payload' => ['external_order_id' => $externalOrderId],
        ]);
    }
}
