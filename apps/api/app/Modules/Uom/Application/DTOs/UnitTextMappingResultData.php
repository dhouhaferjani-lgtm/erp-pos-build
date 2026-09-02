<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UnitTextMappingResultData extends Data
{
    public function __construct(
        public ?string $sourceText,
        public string $targetUnitId,
        public string $targetUnitCode,
        public int $productCount,
        public int $importRowCount,
        public bool $applied,
        public bool $aliasStored,
    ) {}
}
