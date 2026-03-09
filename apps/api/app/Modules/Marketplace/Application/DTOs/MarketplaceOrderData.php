<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\DTOs;

use App\Modules\Marketplace\Domain\Enums\MarketplaceOrderStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MarketplaceOrderData extends Data
{
    /**
     * @param  array<int, array{listing_id: string, article_name: string, quantity: string, unit_price: string, line_total: string}>  $lines
     */
    public function __construct(
        public string $id,
        public string $order_number,
        public MarketplaceOrderStatus $order_status,
        public string $seller_name,
        public string $currency,
        public string $subtotal,
        public string $commission_amount,
        public string $total,
        public ?string $buyer_document_id,
        public ?string $seller_document_id,
        public array $lines,
        public string $created_at,
        public ?string $confirmed_at,
        public ?string $shipped_at,
        public ?string $delivered_at,
    ) {}

    public static function fromModel(MarketplaceOrder $order): self
    {
        $order->loadMissing(['seller', 'lines']);

        $lines = $order->lines->map(fn ($line): array => [
            'listing_id' => $line->listing_id,
            'article_name' => $line->article_name,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'line_total' => (string) $line->line_total,
        ])->all();

        return new self(
            id: $order->id,
            order_number: $order->order_number,
            order_status: $order->order_status,
            seller_name: $order->seller->display_name,
            currency: $order->currency,
            subtotal: (string) $order->subtotal,
            commission_amount: (string) $order->commission_amount,
            total: (string) $order->total,
            buyer_document_id: $order->buyer_document_id,
            seller_document_id: $order->seller_document_id,
            lines: $lines,
            created_at: $order->created_at?->toIso8601String() ?? '',
            confirmed_at: $order->confirmed_at?->toIso8601String(),
            shipped_at: $order->shipped_at?->toIso8601String(),
            delivered_at: $order->delivered_at?->toIso8601String(),
        );
    }
}
