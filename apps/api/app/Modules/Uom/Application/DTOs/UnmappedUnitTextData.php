<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UnmappedUnitTextData extends Data
{
    public function __construct(
        public ?string $sourceText,
        public int $productCount,
        public int $importRowCount,
        public int $pendingImportCount,
        public int $totalCount,
    ) {}
}
