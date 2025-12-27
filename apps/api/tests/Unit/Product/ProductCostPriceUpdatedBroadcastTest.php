<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Infrastructure\Broadcasting\ProductCostPriceUpdatedBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class ProductCostPriceUpdatedBroadcastTest extends TestCase
{
    public function test_broadcast_channel_name_includes_tenant_company_product(): void
    {
        $event = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'purchase_receipt',
            referenceDocument: 'PO-001',
        );

        $broadcast = new ProductCostPriceUpdatedBroadcast($event);
        $channels = $broadcast->broadcastOn();

        $this->assertIsArray($channels);
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        // Laravel automatically prepends 'private-' to PrivateChannel names
        $this->assertEquals(
            'private-tenant.tenant-abc.company.company-xyz.product.prod-123',
            $channels[0]->name
        );
    }

    public function test_broadcast_event_name_is_correct(): void
    {
        $event = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'purchase_receipt',
            referenceDocument: null,
        );

        $broadcast = new ProductCostPriceUpdatedBroadcast($event);

        $this->assertEquals('product.cost-price-updated', $broadcast->broadcastAs());
    }

    public function test_broadcast_payload_structure(): void
    {
        $event = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'purchase_receipt',
            referenceDocument: 'PO-001',
        );

        $broadcast = new ProductCostPriceUpdatedBroadcast($event);
        $payload = $broadcast->broadcastWith();

        $this->assertArrayHasKey('productId', $payload);
        $this->assertArrayHasKey('productSku', $payload);
        $this->assertArrayHasKey('oldCostPrice', $payload);
        $this->assertArrayHasKey('newCostPrice', $payload);
        $this->assertArrayHasKey('oldSalePrice', $payload);
        $this->assertArrayHasKey('newSalePrice', $payload);
        $this->assertArrayHasKey('reason', $payload);
        $this->assertArrayHasKey('timestamp', $payload);

        $this->assertEquals('prod-123', $payload['productId']);
        $this->assertEquals('SKU-001', $payload['productSku']);
        $this->assertEquals('10.00', $payload['oldCostPrice']);
        $this->assertEquals('12.00', $payload['newCostPrice']);
        $this->assertEquals('15.00', $payload['oldSalePrice']);
        $this->assertEquals('18.00', $payload['newSalePrice']);
        $this->assertEquals('purchase_receipt', $payload['reason']);
    }

    public function test_broadcast_payload_excludes_reference_when_null(): void
    {
        $event = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'manual_adjustment',
            referenceDocument: null,
        );

        $broadcast = new ProductCostPriceUpdatedBroadcast($event);
        $payload = $broadcast->broadcastWith();

        $this->assertArrayNotHasKey('referenceDocument', $payload);
    }

    public function test_broadcast_payload_includes_reference_when_present(): void
    {
        $event = new ProductCostPriceUpdated(
            productId: 'prod-123',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
            productSku: 'SKU-001',
            oldCostPrice: '10.00',
            newCostPrice: '12.00',
            oldSalePrice: '15.00',
            newSalePrice: '18.00',
            reason: 'purchase_receipt',
            referenceDocument: 'PO-001',
        );

        $broadcast = new ProductCostPriceUpdatedBroadcast($event);
        $payload = $broadcast->broadcastWith();

        $this->assertArrayHasKey('referenceDocument', $payload);
        $this->assertEquals('PO-001', $payload['referenceDocument']);
    }
}
