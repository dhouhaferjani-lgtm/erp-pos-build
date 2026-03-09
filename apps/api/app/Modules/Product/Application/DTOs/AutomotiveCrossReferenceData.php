<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\AutomotiveProductCrossReference;
use App\Modules\Product\Domain\Enums\CrossReferenceType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AutomotiveCrossReferenceData extends Data
{
    public function __construct(
        public string $id,
        public CrossReferenceType $reference_type,
        public string $reference_number,
        public ?string $manufacturer_name,
        public ?string $platform_cross_ref_id,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(AutomotiveProductCrossReference $crossReference): self
    {
        return new self(
            id: $crossReference->id,
            reference_type: $crossReference->reference_type,
            reference_number: $crossReference->reference_number,
            manufacturer_name: $crossReference->manufacturer_name,
            platform_cross_ref_id: $crossReference->platform_cross_ref_id,
            created_at: $crossReference->created_at?->toIso8601String() ?? '',
            updated_at: $crossReference->updated_at?->toIso8601String(),
        );
    }
}
