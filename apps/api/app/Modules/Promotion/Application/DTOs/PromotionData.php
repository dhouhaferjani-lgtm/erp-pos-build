<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application\DTOs;

use App\Modules\Promotion\Domain\Entities\Promotion;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PromotionData extends Data
{
    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<int>|null  $days_of_week
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $type,
        public string $status,
        public int $priority,
        public bool $is_exclusive,
        public string $stacking_group,
        public ?string $starts_at,
        public ?string $ends_at,
        public ?array $days_of_week,
        public ?string $time_from,
        public ?string $time_until,
        public array $conditions,
        public string $discount_type,
        public string $discount_value,
        public ?string $max_discount_amount,
        public string $applies_to,
        public ?int $usage_limit,
        public int $usage_count,
        public ?array $metadata,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Promotion $promotion): self
    {
        return new self(
            id: $promotion->id,
            name: $promotion->name,
            description: $promotion->description,
            type: $promotion->type->value,
            status: $promotion->status->value,
            priority: $promotion->priority,
            is_exclusive: $promotion->is_exclusive,
            stacking_group: $promotion->stacking_group,
            starts_at: $promotion->starts_at?->toIso8601String(),
            ends_at: $promotion->ends_at?->toIso8601String(),
            days_of_week: $promotion->days_of_week,
            time_from: $promotion->time_from,
            time_until: $promotion->time_until,
            conditions: $promotion->conditions ?? [],
            discount_type: $promotion->discount_type->value,
            discount_value: $promotion->discount_value,
            max_discount_amount: $promotion->max_discount_amount,
            applies_to: $promotion->applies_to->value,
            usage_limit: $promotion->usage_limit,
            usage_count: $promotion->usage_count,
            metadata: $promotion->metadata,
            created_at: $promotion->created_at?->toIso8601String() ?? '',
            updated_at: $promotion->updated_at?->toIso8601String(),
        );
    }
}
