<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Application\Commands\CreateChannelCommand;
use App\Modules\Channel\Application\Jobs\ChannelReconciliationJob;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\Channel\ExampleTestAdapter;
use Tests\TestCase;

final class ChannelServiceTest extends TestCase
{
    use CreatesChannelSchema;

    public function test_create_channel_uses_generic_adapter_type_string(): void
    {
        $ids = $this->seedBaseRows();
        $service = $this->app->make(ChannelService::class);

        $channel = $service->create(new CreateChannelCommand(
            companyId: $ids['company_id'],
            name: 'Example channel',
            adapterType: 'example_test',
            metadata: ['store_url' => 'https://example.test'],
        ));

        $this->assertSame('example_test', $channel->adapter_type);
        $this->assertSame(ChannelConnectionStatus::Pending, $channel->connection_status);
        $this->assertSame(['store_url' => 'https://example.test'], $channel->metadata);
    }

    public function test_test_connection_reports_clear_error_when_adapter_is_not_registered(): void
    {
        $ids = $this->seedBaseRows();
        $service = $this->app->make(ChannelService::class);
        $channel = $service->create(new CreateChannelCommand($ids['company_id'], 'Missing adapter', 'example_test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No adapter registered for type example_test. Concrete adapters ship in a follow-up sprint.');

        $service->testConnection($channel->id);
    }

    public function test_test_connection_delegates_to_registered_test_adapter(): void
    {
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $service = $this->app->make(ChannelService::class);
        $channel = $service->create(new CreateChannelCommand($ids['company_id'], 'Registered adapter', 'example_test'));

        $result = $service->testConnection($channel->id);

        $this->assertTrue($result->successful);
        $this->assertSame('Example test adapter connected.', $result->message);
        $this->assertSame(ChannelConnectionStatus::Connected, $channel->refresh()->connection_status);
    }

    public function test_publish_product_is_idempotent_for_same_payload_hash(): void
    {
        $ids = $this->seedBaseRows();
        $adapter = new ExampleTestAdapter;
        $this->app->make(AdapterRegistry::class)->register('example_test', $adapter);
        $service = $this->app->make(ChannelService::class);
        $channel = $service->create(new CreateChannelCommand($ids['company_id'], 'Publisher', 'example_test'));

        $first = $service->publishProduct($channel->id, $ids['product_id'], null, ['price' => '42.500']);
        $second = $service->publishProduct($channel->id, $ids['product_id'], null, ['price' => '42.500']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $adapter->productPushCount);
        $this->assertNotNull($second->last_sync_hash);
        $this->assertSame(1, ChannelProductMapping::query()->count());
    }

    public function test_publish_product_rejects_product_outside_channel_company(): void
    {
        $ids = $this->seedBaseRows();
        DB::table('companies')->insert([
            'id' => '55555555-5555-5555-5555-555555555555',
            'tenant_id' => $ids['tenant_id'],
        ]);
        DB::table('products')->insert([
            'id' => '66666666-6666-6666-6666-666666666666',
            'company_id' => '55555555-5555-5555-5555-555555555555',
            'name' => 'Foreign product',
            'sku' => 'FOREIGN-1',
        ]);
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $service = $this->app->make(ChannelService::class);
        $channel = $service->create(new CreateChannelCommand($ids['company_id'], 'Publisher', 'example_test'));

        $this->expectException(ModelNotFoundException::class);

        $service->publishProduct($channel->id, '66666666-6666-6666-6666-666666666666', null, []);
    }

    public function test_manual_resync_dispatches_reconciliation_job(): void
    {
        Queue::fake();
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $service = $this->app->make(ChannelService::class);
        $channel = $service->create(new CreateChannelCommand($ids['company_id'], 'Resync channel', 'example_test'));

        $result = $service->manualResync($channel->id, $ids['company_id']);

        $this->assertTrue($result->successful);
        Queue::assertPushed(
            ChannelReconciliationJob::class,
            fn (ChannelReconciliationJob $job): bool => $job->channelId === $channel->id
                && $job->tenantId === $ids['tenant_id'],
        );
    }
}
