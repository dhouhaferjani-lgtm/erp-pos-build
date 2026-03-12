<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Receipt;

/**
 * Service for converting a POS order into a fiscal receipt.
 *
 * Maps order lines to the format expected by ReceiptCreationService,
 * which handles stock decrement, hash chain, and all fiscal compliance.
 */
final class OrderToReceiptService
{
    public function __construct(
        private readonly ReceiptCreationService $receiptCreationService,
    ) {}

    /**
     * Convert an order to a receipt.
     *
     * Maps all order lines to the receipt line format and delegates
     * to ReceiptCreationService for fiscal processing.
     *
     * @throws \RuntimeException If receipt creation fails
     * @throws \InvalidArgumentException If order has no lines
     */
    public function convertToReceipt(Order $order): Receipt
    {
        $order->loadMissing('lines');

        if ($order->lines->isEmpty()) {
            throw new \InvalidArgumentException('Cannot convert an order with no lines to a receipt');
        }

        // Map order lines to ReceiptCreationService format
        /** @var array<int, array{product_id?: string, composite_item_id?: string, quantity: string, unit_price: string, modifiers?: array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>, discount_amount?: string, discount_type?: string}> $receiptLines */
        $receiptLines = [];

        /** @var OrderLine $line */
        foreach ($order->lines as $line) {
            $receiptLine = [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
            ];

            // Pass through discount if present
            if (bccomp($line->discount_amount, '0', 4) > 0) {
                $receiptLine['discount_amount'] = $line->discount_amount;
                $receiptLine['discount_type'] = 'fixed';
            }

            // Pass through modifiers if present
            if (! empty($line->modifiers)) {
                /** @var array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}> $modifiers */
                $modifiers = $line->modifiers;
                $receiptLine['modifiers'] = $modifiers;
            }

            $receiptLines[] = $receiptLine;
        }

        return $this->receiptCreationService->createReceipt(
            terminalId: $order->terminal_id,
            lines: $receiptLines,
            customerId: $order->partner_id,
            notes: $order->notes,
            consumptionMode: $order->consumption_mode,
        );
    }
}
