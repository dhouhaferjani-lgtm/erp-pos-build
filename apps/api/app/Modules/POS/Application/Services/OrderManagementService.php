<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\OrderClosed;
use App\Modules\POS\Domain\Events\OrderSentToKitchen;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing POS orders throughout their lifecycle.
 *
 * Handles order creation, line management, kitchen workflow,
 * and conversion to receipt on close.
 */
final class OrderManagementService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Create a new order on a terminal.
     *
     * @throws \RuntimeException If terminal is not active or no active shift
     */
    public function createOrder(
        string $terminalId,
        string $shiftId,
        ?string $tableId,
        ?string $partnerId,
        ?string $customerName,
        ?ConsumptionMode $consumptionMode,
        ?string $notes,
    ): Order {
        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($terminalId, $shiftId, $tableId, $partnerId, $customerName, $consumptionMode, $notes, $companyId): Order {
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $terminalId)
                ->firstOrFail();

            if (! $terminal->isActive()) {
                throw new \RuntimeException('Terminal is not active');
            }

            /** @var Shift $shift */
            $shift = Shift::with('cashier')
                ->where('id', $shiftId)
                ->where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->firstOrFail();

            /** @var Company $company */
            $company = $terminal->company ?? Company::findOrFail($terminal->company_id);

            // Generate order number: sequential per terminal per day
            $today = Carbon::today();
            /** @var int|null $maxNumber */
            $maxNumber = Order::where('terminal_id', $terminal->id)
                ->whereDate('opened_at', $today)
                ->max(DB::raw("CAST(REPLACE(order_number, '#', '') AS INTEGER)"));

            $nextNum = ($maxNumber ?? 0) + 1;
            $orderNumber = '#' . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

            // Resolve customer info
            $customerIdentifier = null;
            $resolvedPartnerId = null;
            if ($partnerId !== null) {
                $partner = \App\Modules\Partner\Domain\Partner::find($partnerId);
                if ($partner !== null) {
                    $customerName = $customerName ?? $partner->name;
                    $customerIdentifier = $partner->phone ?? $partner->email ?? null;
                    $resolvedPartnerId = $partner->id;
                }
            }

            $cashierName = $shift->cashier->name ?? 'Unknown';
            $currency = $company->currency ?? 'TND';

            /** @var Order $order */
            $order = Order::create([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'table_id' => $tableId,
                'order_number' => $orderNumber,
                'status' => OrderStatus::Open,
                'cashier_id' => $shift->cashier_id,
                'cashier_name' => $cashierName,
                'customer_name' => $customerName,
                'customer_identifier' => $customerIdentifier,
                'partner_id' => $resolvedPartnerId,
                'subtotal' => '0.0000',
                'tax_amount' => '0.0000',
                'discount_amount' => '0.0000',
                'total' => '0.0000',
                'currency' => $currency,
                'consumption_mode' => $consumptionMode,
                'notes' => $notes,
                'opened_at' => Carbon::now(),
            ]);

            return $order->load('lines');
        });
    }

    /**
     * Add a line item to an order.
     *
     * @param  array<string, mixed>|null  $modifiers
     * @throws \RuntimeException If order is not open
     * @throws \InvalidArgumentException If product not found
     */
    public function addLine(
        string $orderId,
        string $productId,
        string $quantity,
        string $unitPrice,
        string $taxRate,
        ?string $discountAmount,
        ?array $modifiers,
        ?string $specialInstructions,
    ): OrderLine {
        return DB::transaction(function () use ($orderId, $productId, $quantity, $unitPrice, $taxRate, $discountAmount, $modifiers, $specialInstructions): OrderLine {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (! $order->isOpen()) {
                throw new \RuntimeException('Cannot add lines to a non-open order');
            }

            /** @var Product $product */
            $product = Product::findOrFail($productId);

            // Calculate line totals
            $lineCalc = $this->calculateLineTotals($quantity, $unitPrice, $taxRate, $discountAmount);

            // Determine next line number
            $nextLineNumber = ($order->lines()->max('line_number') ?? 0) + 1;

            /** @var OrderLine $line */
            $line = OrderLine::create([
                'order_id' => $order->id,
                'line_number' => $nextLineNumber,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_name' => null,
                'barcode' => $product->barcode,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $lineCalc['discount_amount'],
                'tax_rate' => $taxRate,
                'tax_amount' => $lineCalc['tax_amount'],
                'line_total' => $lineCalc['line_total'],
                'modifiers' => $modifiers,
                'special_instructions' => $specialInstructions,
                'status' => OrderLineStatus::Pending,
            ]);

            $this->recalculateOrderTotals($order);

            return $line;
        });
    }

    /**
     * Modify an existing order line.
     *
     * @param  array<string, mixed>|null  $modifiers
     * @throws \RuntimeException If order is not open
     */
    public function modifyLine(
        string $orderId,
        string $lineId,
        ?string $quantity,
        ?string $discountAmount,
        ?array $modifiers,
        ?string $specialInstructions,
    ): OrderLine {
        return DB::transaction(function () use ($orderId, $lineId, $quantity, $discountAmount, $modifiers, $specialInstructions): OrderLine {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (! $order->isOpen()) {
                throw new \RuntimeException('Cannot modify lines on a non-open order');
            }

            /** @var OrderLine $line */
            $line = OrderLine::where('order_id', $order->id)->findOrFail($lineId);

            $updatedQuantity = $quantity ?? $line->quantity;
            $updatedDiscount = $discountAmount ?? (string) $line->discount_amount;

            $lineCalc = $this->calculateLineTotals(
                $updatedQuantity,
                $line->unit_price,
                $line->tax_rate,
                $updatedDiscount,
            );

            $updateData = [
                'quantity' => $updatedQuantity,
                'discount_amount' => $lineCalc['discount_amount'],
                'tax_amount' => $lineCalc['tax_amount'],
                'line_total' => $lineCalc['line_total'],
            ];

            if ($modifiers !== null) {
                $updateData['modifiers'] = $modifiers;
            }

            if ($specialInstructions !== null) {
                $updateData['special_instructions'] = $specialInstructions;
            }

            $line->update($updateData);

            $this->recalculateOrderTotals($order);

            return $line->fresh() ?? $line;
        });
    }

    /**
     * Remove a line from an order.
     *
     * @throws \RuntimeException If order is not open
     */
    public function removeLine(string $orderId, string $lineId): void
    {
        DB::transaction(function () use ($orderId, $lineId): void {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (! $order->isOpen()) {
                throw new \RuntimeException('Cannot remove lines from a non-open order');
            }

            $deleted = OrderLine::where('order_id', $order->id)
                ->where('id', $lineId)
                ->delete();

            if ($deleted === 0) {
                throw new \InvalidArgumentException('Order line not found');
            }

            $this->recalculateOrderTotals($order);
        });
    }

    /**
     * Send an order to the kitchen.
     *
     * @throws \RuntimeException If order cannot be sent to kitchen
     */
    public function sendToKitchen(string $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $order */
            $order = Order::lockForUpdate()->with('lines')->findOrFail($orderId);

            if (! $order->canBeSentToKitchen()) {
                throw new \RuntimeException('Order cannot be sent to kitchen. Must be open with at least one line.');
            }

            $now = Carbon::now();

            $order->update([
                'status' => OrderStatus::SentToKitchen,
                'sent_at' => $now,
            ]);

            // Mark all pending lines as sent
            OrderLine::where('order_id', $order->id)
                ->where('status', OrderLineStatus::Pending)
                ->update([
                    'status' => OrderLineStatus::Sent,
                    'sent_at' => $now,
                ]);

            OrderSentToKitchen::dispatch($order->id);

            /** @var Order $freshOrder */
            $freshOrder = $order->fresh(['lines']);

            return $freshOrder;
        });
    }

    /**
     * Close an order and convert it to a receipt.
     *
     * @throws \RuntimeException If order cannot be closed
     */
    public function closeOrder(string $orderId, OrderToReceiptService $orderToReceiptService): Order
    {
        return DB::transaction(function () use ($orderId, $orderToReceiptService): Order {
            /** @var Order $order */
            $order = Order::lockForUpdate()->with('lines')->findOrFail($orderId);

            if (! $order->canBeClosed()) {
                throw new \RuntimeException('Order cannot be closed. Must have lines and not be already closed/cancelled.');
            }

            $receipt = $orderToReceiptService->convertToReceipt($order);

            $order->update([
                'status' => OrderStatus::Closed,
                'closed_at' => Carbon::now(),
                'receipt_id' => $receipt->id,
            ]);

            OrderClosed::dispatch($order->id, $receipt->id);

            /** @var Order $freshOrder */
            $freshOrder = $order->fresh(['lines']);

            return $freshOrder;
        });
    }

    /**
     * Cancel an order.
     *
     * @throws \RuntimeException If order cannot be cancelled
     */
    public function cancelOrder(string $orderId, ?string $reason): Order
    {
        return DB::transaction(function () use ($orderId, $reason): Order {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (! $order->canBeCancelled()) {
                throw new \RuntimeException('Order cannot be cancelled. Already closed or cancelled.');
            }

            $updateData = [
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
            ];

            if ($reason !== null) {
                $updateData['notes'] = $order->notes
                    ? $order->notes . "\nCancellation reason: " . $reason
                    : 'Cancellation reason: ' . $reason;
            }

            $order->update($updateData);

            /** @var Order $freshOrder */
            $freshOrder = $order->fresh(['lines']);

            return $freshOrder;
        });
    }

    /**
     * Recalculate order totals from its lines.
     */
    private function recalculateOrderTotals(Order $order): void
    {
        $scale = $this->scale();

        $subtotal = '0.0000';
        $taxAmount = '0.0000';
        $discountAmount = '0.0000';

        /** @var OrderLine $line */
        foreach ($order->lines()->get() as $line) {
            // line_total = (qty * unit_price) - discount
            // tax is included in line_total (tax-inclusive pricing)
            // subtotal = sum of (line_total - tax_amount) across all lines
            $lineNet = bcsub($line->line_total, $line->tax_amount, $scale);
            $subtotal = bcadd($subtotal, $lineNet, $scale);
            $taxAmount = bcadd($taxAmount, $line->tax_amount, $scale);
            $discountAmount = bcadd($discountAmount, $line->discount_amount, $scale);
        }

        $total = bcadd($subtotal, $taxAmount, $scale);

        $order->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $total,
        ]);
    }

    /**
     * Calculate line totals from quantity, price, tax rate, and discount.
     *
     * @return array{line_total: string, tax_amount: string, discount_amount: string}
     */
    /**
     * @return array{line_total: numeric-string, tax_amount: numeric-string, discount_amount: numeric-string}
     */
    private function calculateLineTotals(
        string $quantity,
        string $unitPrice,
        string $taxRate,
        ?string $discountAmount,
    ): array {
        $scale = $this->scale();

        /** @var numeric-string $qty */
        $qty = $quantity;
        /** @var numeric-string $price */
        $price = $unitPrice;
        /** @var numeric-string $rate */
        $rate = $taxRate;

        // Gross = quantity * unit_price
        $grossTotal = bcmul($qty, $price, $scale);

        // Apply discount
        /** @var numeric-string $discountStr */
        $discountStr = $discountAmount ?? '0.0000';
        /** @var numeric-string $discount */
        $discount = (bccomp($discountStr, '0', $scale) > 0)
            ? $discountStr
            : '0.0000';

        // Ensure discount does not exceed gross total
        if (bccomp($discount, $grossTotal, $scale) > 0) {
            $discount = $grossTotal;
        }

        // line_total = gross - discount
        $lineTotal = bcsub($grossTotal, $discount, $scale);

        // Calculate tax (tax-inclusive): net = lineTotal / (1 + taxRate/100), tax = lineTotal - net
        $taxRateDecimal = bcdiv($rate, '100', 6);
        $divisor = bcadd('1', $taxRateDecimal, 6);
        $netAmount = bcdiv($lineTotal, $divisor, $scale);
        $taxAmount = bcsub($lineTotal, $netAmount, $scale);

        return [
            'line_total' => $lineTotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discount,
        ];
    }
}
