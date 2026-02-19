<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ConversionResultData extends Data
{
    public function __construct(
        public string $originalQuantity,
        public UnitData $originalUnit,
        public string $convertedQuantity,
        public UnitData $convertedUnit,
        public string $conversionFactor,
    ) {}
}
