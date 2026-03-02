<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Application\DTOs;

use App\Modules\Coupon\Domain\Entities\Coupon;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CouponData extends Data
{
    /**
     * @param  array<string>|null  $qualifying_product_ids
     * @param  array<string>|null  $qualifying_category_ids
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $type,
        public string $status,
        public bool $is_single_use,
        public ?int $max_uses,
        public int $use_count,
        public ?int $max_uses_per_customer,
        public string $discount_type,
        public string $discount_value,
        public ?string $max_discount_amount,
        public ?string $minimum_order_amount,
        public ?array $qualifying_product_ids,
        public ?array $qualifying_category_ids,
        public bool $is_exclusive,
        public string $stacking_group,
        public ?string $starts_at,
        public ?string $expires_at,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Coupon $coupon): self
    {
        return new self(
            id: $coupon->id,
            name: $coupon->name,
            code: $coupon->code,
            type: $coupon->type->value,
            status: $coupon->status->value,
            is_single_use: $coupon->is_single_use,
            max_uses: $coupon->max_uses,
            use_count: $coupon->use_count,
            max_uses_per_customer: $coupon->max_uses_per_customer,
            discount_type: $coupon->discount_type,
            discount_value: (string) $coupon->discount_value,
            max_discount_amount: $coupon->max_discount_amount,
            minimum_order_amount: $coupon->minimum_order_amount,
            qualifying_product_ids: $coupon->qualifying_product_ids,
            qualifying_category_ids: $coupon->qualifying_category_ids,
            is_exclusive: $coupon->is_exclusive,
            stacking_group: $coupon->stacking_group,
            starts_at: $coupon->starts_at?->toIso8601String(),
            expires_at: $coupon->expires_at?->toIso8601String(),
            created_at: $coupon->created_at?->toIso8601String() ?? '',
            updated_at: $coupon->updated_at?->toIso8601String(),
        );
    }
}
