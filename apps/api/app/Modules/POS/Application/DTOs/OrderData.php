<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Order;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OrderData extends Data
{
    /**
     * @param  array<int, OrderLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $terminal_id,
        public string $shift_id,
        public ?string $table_id,
        public string $order_number,
        public OrderStatus $status,
        public string $cashier_id,
        public string $cashier_name,
        public ?string $customer_name,
        public ?string $customer_identifier,
        public ?string $partner_id,
        public string $subtotal,
        public string $tax_amount,
        public string $discount_amount,
        public string $total,
        public string $currency,
        public ?ConsumptionMode $consumption_mode,
        public ?string $notes,
        public string $opened_at,
        public ?string $sent_at,
        public ?string $closed_at,
        public ?string $cancelled_at,
        public ?string $receipt_id,
        public array $lines,
    ) {}

    /**
     * Create from an Order model with loaded lines.
     */
    public static function fromModel(Order $order): self
    {
        $lines = $order->relationLoaded('lines')
            ? $order->lines->map(fn ($line) => OrderLineData::fromModel($line))->all()
            : [];

        return new self(
            id: $order->id,
            terminal_id: $order->terminal_id,
            shift_id: $order->shift_id,
            table_id: $order->table_id,
            order_number: $order->order_number,
            status: $order->status,
            cashier_id: $order->cashier_id,
            cashier_name: $order->cashier_name,
            customer_name: $order->customer_name,
            customer_identifier: $order->customer_identifier,
            partner_id: $order->partner_id,
            subtotal: $order->subtotal,
            tax_amount: $order->tax_amount,
            discount_amount: $order->discount_amount,
            total: $order->total,
            currency: $order->currency,
            consumption_mode: $order->consumption_mode,
            notes: $order->notes,
            opened_at: $order->opened_at->toIso8601String(),
            sent_at: $order->sent_at?->toISOString(),
            closed_at: $order->closed_at?->toISOString(),
            cancelled_at: $order->cancelled_at?->toISOString(),
            receipt_id: $order->receipt_id,
            lines: $lines,
        );
    }
}
