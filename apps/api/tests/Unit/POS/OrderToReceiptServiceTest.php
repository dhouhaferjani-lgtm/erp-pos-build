<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Application\Services\OrderToReceiptService;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Receipt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OrderToReceiptService.
 *
 * Uses mocked ReceiptCreationService since receipt creation involves
 * complex fiscal hashing, stock management, etc.
 */
final class OrderToReceiptServiceTest extends TestCase
{
    public function test_convert_to_receipt_maps_lines_correctly(): void
    {
        $receiptCreationService = $this->createMock(ReceiptCreationService::class);

        $mockReceipt = $this->createMock(Receipt::class);
        $mockReceipt->method('__get')->willReturnMap([
            ['id', Str::uuid()->toString()],
        ]);

        $receiptCreationService
            ->expects($this->once())
            ->method('createReceipt')
            ->willReturnCallback(function (
                string $terminalId,
                array $lines,
                ?string $customerId,
                ?string $contactId,
                ?string $notes,
                ?string $transactionDiscountAmount,
                ?string $transactionDiscountReason,
                ?string $couponCode,
                ?string $loyaltyDiscountAmount,
                ?string $loyaltyRewardId,
                ?ConsumptionMode $consumptionMode,
            ) use ($mockReceipt): Receipt {
                $this->assertEquals('terminal-1', $terminalId);
                $this->assertCount(2, $lines);
                $this->assertEquals('product-1', $lines[0]['product_id']);
                $this->assertEquals('2.000', $lines[0]['quantity']);
                $this->assertEquals('10.0000', $lines[0]['unit_price']);
                $this->assertEquals('product-2', $lines[1]['product_id']);
                $this->assertEquals('partner-1', $customerId);
                $this->assertEquals('Test notes', $notes);
                $this->assertEquals(ConsumptionMode::SurPlace, $consumptionMode);

                return $mockReceipt;
            });

        $service = new OrderToReceiptService($receiptCreationService);

        // Build mock order with lines
        $order = $this->createMock(Order::class);
        $order->method('__get')->willReturnMap([
            ['terminal_id', 'terminal-1'],
            ['partner_id', 'partner-1'],
            ['notes', 'Test notes'],
            ['consumption_mode', ConsumptionMode::SurPlace],
        ]);

        $line1 = $this->createMock(OrderLine::class);
        $line1->method('__get')->willReturnMap([
            ['product_id', 'product-1'],
            ['quantity', '2.000'],
            ['unit_price', '10.0000'],
            ['discount_amount', '0.0000'],
            ['modifiers', null],
        ]);

        $line2 = $this->createMock(OrderLine::class);
        $line2->method('__get')->willReturnMap([
            ['product_id', 'product-2'],
            ['quantity', '1.000'],
            ['unit_price', '25.0000'],
            ['discount_amount', '5.0000'],
            ['modifiers', null],
        ]);

        $linesCollection = new Collection([$line1, $line2]);

        $order->method('relationLoaded')->willReturn(true);
        $order->method('loadMissing')->willReturnSelf();
        $order->expects($this->any())->method('__get')
            ->willReturnCallback(function (string $property) use ($linesCollection) {
                return match ($property) {
                    'terminal_id' => 'terminal-1',
                    'partner_id' => 'partner-1',
                    'notes' => 'Test notes',
                    'consumption_mode' => ConsumptionMode::SurPlace,
                    'lines' => $linesCollection,
                    default => null,
                };
            });

        $receipt = $service->convertToReceipt($order);
        $this->assertInstanceOf(Receipt::class, $receipt);
    }

    public function test_convert_empty_order_throws(): void
    {
        $receiptCreationService = $this->createMock(ReceiptCreationService::class);
        $service = new OrderToReceiptService($receiptCreationService);

        $order = $this->createMock(Order::class);
        $order->method('loadMissing')->willReturnSelf();

        $emptyCollection = new Collection([]);
        $order->method('__get')->willReturnCallback(function (string $property) use ($emptyCollection) {
            return match ($property) {
                'lines' => $emptyCollection,
                default => null,
            };
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert an order with no lines');

        $service->convertToReceipt($order);
    }
}
