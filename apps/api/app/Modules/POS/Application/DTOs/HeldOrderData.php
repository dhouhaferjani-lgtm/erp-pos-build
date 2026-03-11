<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\HeldOrder;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class HeldOrderData extends Data
{
    public function __construct(
        public string $id,
        public string $terminal_id,
        public string $shift_id,
        public string $cashier_id,
        public ?string $label,
        /** @var array<string, mixed> */
        public array $cart_snapshot,
        public string $status,
        public string $held_at,
        public ?string $expires_at,
        public ?string $recalled_at,
        public int $line_count,
        public ?string $total,
        public string $created_at,
    ) {}

    /**
     * Create a DTO from a HeldOrder model instance.
     */
    public static function fromModel(HeldOrder $heldOrder): self
    {
        return new self(
            id: $heldOrder->id,
            terminal_id: $heldOrder->terminal_id,
            shift_id: $heldOrder->shift_id,
            cashier_id: $heldOrder->cashier_id,
            label: $heldOrder->label,
            cart_snapshot: $heldOrder->cart_snapshot,
            status: $heldOrder->status->value,
            held_at: $heldOrder->held_at->toIso8601String(),
            expires_at: $heldOrder->expires_at?->toIso8601String(),
            recalled_at: $heldOrder->recalled_at?->toIso8601String(),
            line_count: $heldOrder->getLineCount(),
            total: $heldOrder->getTotal(),
            created_at: $heldOrder->created_at->toIso8601String(),
        );
    }
}
