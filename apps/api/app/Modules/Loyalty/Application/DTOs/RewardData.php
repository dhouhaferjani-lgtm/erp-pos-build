<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RewardData extends Data
{
    /**
     * @param  array<int, string>|null  $tier_ids
     */
    public function __construct(
        public string $id,
        public string $program_id,
        public string $name,
        public ?string $description,
        public RewardType $reward_type,
        public string $points_cost,
        public ?string $reward_value,
        public ?QualifyingItemsData $qualifying_items,
        public ?string $max_discount,
        public ?string $min_order_value,
        public ?array $tier_ids,
        public bool $is_active,
        public ?int $quantity_available,
        public ?int $quantity_per_member,
        public ?string $start_date,
        public ?string $end_date,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Reward $reward): self
    {
        return new self(
            id: $reward->id,
            program_id: $reward->program_id,
            name: $reward->name,
            description: $reward->description,
            reward_type: $reward->reward_type,
            points_cost: (string) $reward->points_cost,
            reward_value: $reward->reward_value !== null ? (string) $reward->reward_value : null,
            qualifying_items: $reward->qualifying_items !== null
                ? QualifyingItemsData::from($reward->qualifying_items)
                : null,
            max_discount: $reward->max_discount !== null ? (string) $reward->max_discount : null,
            min_order_value: $reward->min_order_value !== null ? (string) $reward->min_order_value : null,
            tier_ids: $reward->tier_ids,
            is_active: $reward->is_active,
            quantity_available: $reward->quantity_available,
            quantity_per_member: $reward->quantity_per_member,
            start_date: $reward->start_date?->toIso8601String(),
            end_date: $reward->end_date?->toIso8601String(),
            created_at: $reward->created_at?->toIso8601String() ?? '',
            updated_at: $reward->updated_at?->toIso8601String(),
        );
    }
}
