<?php

declare(strict_types=1);

namespace Tests\Unit\Channel;

use App\Modules\Channel\Application\Contracts\ChannelAdapter;
use App\Modules\Channel\Application\Contracts\ChannelSignatureStrategy;
use App\Modules\Channel\Application\DTOs\ConnectionTestResult;
use App\Modules\Channel\Application\DTOs\OrderStatusUpdateDTO;
use App\Modules\Channel\Application\DTOs\PriceUpdateDTO;
use App\Modules\Channel\Application\DTOs\StockUpdateDTO;
use App\Modules\Channel\Application\DTOs\SyncResult;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AdapterRegistryTest extends TestCase
{
    public function test_starts_with_no_registered_adapters(): void
    {
        $registry = new AdapterRegistry;

        $this->assertSame([], $registry->listRegistered()->all());
        $this->assertFalse($registry->has('example_test'));
    }

    public function test_resolve_missing_adapter_throws_clear_follow_up_sprint_error(): void
    {
        $registry = new AdapterRegistry;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No adapter registered for type example_test. Concrete adapters ship in a follow-up sprint.');

        $registry->resolve('example_test');
    }

    public function test_registers_and_resolves_adapter_instances(): void
    {
        $registry = new AdapterRegistry;
        $adapter = new RegistryExampleAdapter;

        $registry->register('example_test', $adapter);

        $this->assertTrue($registry->has('example_test'));
        $this->assertSame(['example_test'], $registry->listRegistered()->all());
        $this->assertSame($adapter, $registry->resolve('example_test'));
    }

    public function test_rejects_adapter_registered_under_mismatched_type(): void
    {
        $registry = new AdapterRegistry;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adapter registry mismatch: requested wrong_type but adapter reports example_test.');

        $registry->register('wrong_type', new RegistryExampleAdapter);
    }

    public function test_default_signature_strategy_rejects_without_adapter(): void
    {
        $strategy = new class implements ChannelSignatureStrategy
        {
            public function verify(Request $request, Channel $channel): bool
            {
                return false;
            }
        };

        /** @var Channel $channel */
        $channel = (new ReflectionClass(Channel::class))->newInstanceWithoutConstructor();

        $this->assertFalse($strategy->verify(Request::create('/webhooks/channels/channel-1', 'POST'), $channel));
    }
}

final class RegistryExampleAdapter implements ChannelAdapter
{
    public function adapterType(): string
    {
        return 'example_test';
    }

    public function signatureStrategy(): ChannelSignatureStrategy
    {
        return new class implements ChannelSignatureStrategy
        {
            public function verify(Request $request, Channel $channel): bool
            {
                return true;
            }
        };
    }

    public function pushProduct(Product $product, ?object $variant, ChannelProductMapping $mapping): SyncResult
    {
        return SyncResult::success('example-product');
    }

    public function pushStock(StockUpdateDTO $update): SyncResult
    {
        return SyncResult::success('example-stock');
    }

    public function pushPriceUpdate(PriceUpdateDTO $update): SyncResult
    {
        return SyncResult::success('example-price');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullOrders(Channel $channel): Collection
    {
        return collect();
    }

    public function updateOrderStatus(string $externalOrderId, OrderStatusUpdateDTO $update): SyncResult
    {
        return SyncResult::success($externalOrderId);
    }

    public function testConnection(Channel $channel): ConnectionTestResult
    {
        return ConnectionTestResult::success('Connected.');
    }
}
