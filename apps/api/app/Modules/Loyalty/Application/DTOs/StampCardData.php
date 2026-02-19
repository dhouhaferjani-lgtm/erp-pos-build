<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class StampCardData extends Data
{
    public function __construct(
        public string $id,
        public string $program_id,
        public string $name,
        public int $stamps_required,
        public int $stamps_per_item,
        public QualifyingItemsData $qualifying_items,
        public string $reward_id,
        public ?int $max_active_cards,
        public ?int $expiry_days,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(StampCardDefinition $stampCard): self
    {
        return new self(
            id: $stampCard->id,
            program_id: $stampCard->program_id,
            name: $stampCard->name,
            stamps_required: $stampCard->stamps_required,
            stamps_per_item: $stampCard->stamps_per_item,
            qualifying_items: QualifyingItemsData::from($stampCard->qualifying_items),
            reward_id: $stampCard->reward_id,
            max_active_cards: $stampCard->max_active_cards,
            expiry_days: $stampCard->expiry_days,
            created_at: $stampCard->created_at?->toIso8601String() ?? '',
            updated_at: $stampCard->updated_at?->toIso8601String(),
        );
    }
}
