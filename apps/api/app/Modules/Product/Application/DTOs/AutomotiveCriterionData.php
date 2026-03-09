<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\AutomotiveProductCriterion;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AutomotiveCriterionData extends Data
{
    public function __construct(
        public string $id,
        public string $criteria_key,
        public string $criteria_label,
        public string $value,
        public ?string $unit,
        public int $sort_order,
        public ?string $platform_criteria_id,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(AutomotiveProductCriterion $criterion): self
    {
        return new self(
            id: $criterion->id,
            criteria_key: $criterion->criteria_key,
            criteria_label: $criterion->criteria_label,
            value: $criterion->value,
            unit: $criterion->unit,
            sort_order: $criterion->sort_order,
            platform_criteria_id: $criterion->platform_criteria_id,
            created_at: $criterion->created_at?->toIso8601String() ?? '',
            updated_at: $criterion->updated_at?->toIso8601String(),
        );
    }
}
