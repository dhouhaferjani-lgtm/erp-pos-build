<?php

declare(strict_types=1);

namespace Tests\Feature\Channel;

use App\Modules\Channel\Application\Commands\CreateChannelCommand;
use App\Modules\Channel\Application\Jobs\IngestChannelOrderJob;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Channel\ExampleTestAdapter;
use Tests\Fixtures\Channel\RejectingSignatureAdapter;
use Tests\TestCase;

final class ChannelWebhookTest extends TestCase
{
    use CreatesChannelSchema;

    public function test_webhook_accepts_registered_signature_strategy_creates_order_and_dispatches_job(): void
    {
        Queue::fake();
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Webhook channel', 'example_test'));

        $response = $this->postJson("/api/v1/webhooks/channels/{$channel->id}", [
            'external_order_id' => 'ORDER-1',
            'total' => '42.500',
        ], [
            'X-Channel-Timestamp' => (string) time(),
        ]);

        $response->assertAccepted();
        $this->assertDatabaseHas('channel_orders', [
            'channel_id' => $channel->id,
            'external_order_id' => 'ORDER-1',
            'status' => 'pending',
        ]);
        Queue::assertPushed(IngestChannelOrderJob::class);
    }

    public function test_webhook_rejects_when_registered_signature_strategy_fails(): void
    {
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('rejecting_test', new RejectingSignatureAdapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Rejecting channel', 'rejecting_test'));

        $this->postJson("/api/v1/webhooks/channels/{$channel->id}", [
            'external_order_id' => 'ORDER-2',
        ], [
            'X-Channel-Timestamp' => (string) time(),
        ])->assertForbidden();

        $this->assertSame(0, ChannelOrder::query()->count());
    }

    public function test_webhook_rejects_expired_replay_timestamp(): void
    {
        $ids = $this->seedBaseRows();
        $this->app->make(AdapterRegistry::class)->register('example_test', new ExampleTestAdapter);
        $channel = $this->app->make(ChannelService::class)
            ->create(new CreateChannelCommand($ids['company_id'], 'Expired channel', 'example_test'));

        $this->postJson("/api/v1/webhooks/channels/{$channel->id}", [
            'external_order_id' => 'ORDER-3',
        ], [
            'X-Channel-Timestamp' => (string) (time() - 301),
        ])->assertForbidden();

        $this->assertSame(0, ChannelOrder::query()->count());
    }
}
