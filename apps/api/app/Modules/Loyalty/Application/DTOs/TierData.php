<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Enums\QualificationType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TierData extends Data
{
    public function __construct(
        public string $id,
        public string $program_id,
        public string $name,
        public int $level,
        public ?string $icon,
        public ?string $color,
        public QualificationType $qualification_type,
        public string $qualification_threshold,
        public ?int $qualification_period_months,
        public string $earning_multiplier,
        public ?TierBenefitsData $benefits,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Tier $tier): self
    {
        return new self(
            id: $tier->id,
            program_id: $tier->program_id,
            name: $tier->name,
            level: $tier->level,
            icon: $tier->icon,
            color: $tier->color,
            qualification_type: $tier->qualification_type,
            qualification_threshold: (string) $tier->qualification_threshold,
            qualification_period_months: $tier->qualification_period_months,
            earning_multiplier: (string) $tier->earning_multiplier,
            benefits: $tier->benefits !== null
                ? TierBenefitsData::from($tier->benefits)
                : null,
            created_at: $tier->created_at?->toIso8601String() ?? '',
            updated_at: $tier->updated_at?->toIso8601String(),
        );
    }
}
