<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Application\Commands\CreateChannelCommand;
use App\Modules\Channel\Application\Jobs\DispatchStockChangeToChannelJob;
use App\Modules\Channel\Application\Listeners\DispatchStockChangeToChannels;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Enums\SyncOperationStatus;
use App\Modules\Channel\Domain\Models\ChannelSyncOperation;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\Channel\ExampleTestAdapter;
use Tests\Fixtures\Channel\FailingStockAdapter;
use Tests\TestCase;

final class ChannelDispatchJobsTest extends TestCase
{
    use CreatesChannelSchema;

    public function test_stock_change_listener_debounces_burst_to_single_channel_dispatch(): void
    {
        Queue::fake();
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Stock channel', 'example_test'));
        $channel->forceFill(['connection_status' => ChannelConnectionStatus::Connected])->save();
        $this->app->make(ChannelService::class)
            ->publishProduct($channel->id, $ids['product_id'], null, ['stock' => '10']);

        $listener = $this->app->make(DispatchStockChangeToChannels::class);

        for ($i = 0; $i < 5; $i++) {
            $listener->handle(new StockMovementRecorded(
                movementId: 'movement-'.$i,
                tenantId: 'tenant-1',
                companyId: $ids['company_id'],
                productId: $ids['product_id'],
                locationId: '44444444-4444-4444-4444-444444444444',
                movementType: 'adjustment',
                quantity: '1',
                unitCost: '0',
                totalCost: '0',
                newStockLevel: '10',
            ));
        }

        Queue::assertPushed(DispatchStockChangeToChannelJob::class, 1);
    }

    public function test_stock_dispatch_job_uses_latest_debounced_stock_level(): void
    {
        $ids = $this->seedBaseRows();
        $adapter = new ExampleTestAdapter;
        $this->app->make(AdapterRegistry::class)->register('example_test', $adapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Stock channel', 'example_test'));
        $idempotencyKey = sprintf('channel-stock-dispatch:%s:%s:%s', $channel->id, $ids['product_id'], '44444444-4444-4444-4444-444444444444');
        Cache::put($idempotencyKey.':latest_stock_level', '12', 300);

        (new DispatchStockChangeToChannelJob(
            channelId: $channel->id,
            productId: $ids['product_id'],
            variantId: null,
            locationId: '44444444-4444-4444-4444-444444444444',
            newStockLevel: '10',
            idempotencyKey: $idempotencyKey,
            tenantId: $ids['tenant_id'],
        ))->handle($this->app->make(AdapterRegistry::class));

        $this->assertSame('12', $adapter->lastStockQuantity);
        $this->assertSame(SyncOperationStatus::Acknowledged, ChannelSyncOperation::query()->firstOrFail()->status);
    }

    public function test_failed_stock_dispatch_persists_failed_operation_state(): void
    {
        $ids = $this->seedBaseRows();
        $registry = $this->app->make(AdapterRegistry::class);
        $registry->register('failing_stock_test', new FailingStockAdapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Failing stock channel', 'failing_stock_test'));

        $this->expectException(RuntimeException::class);

        try {
            (new DispatchStockChangeToChannelJob(
                channelId: $channel->id,
                productId: $ids['product_id'],
                variantId: null,
                locationId: '44444444-4444-4444-4444-444444444444',
                newStockLevel: '10',
                idempotencyKey: 'failing-stock-key',
                tenantId: $ids['tenant_id'],
            ))->handle($registry);
        } finally {
            $operation = ChannelSyncOperation::query()->where('idempotency_key', 'failing-stock-key')->firstOrFail();

            $this->assertSame(SyncOperationStatus::Failed, $operation->status);
            $this->assertSame(1, $operation->attempt_count);
            $this->assertNotNull($operation->next_retry_at);
        }
    }
}
