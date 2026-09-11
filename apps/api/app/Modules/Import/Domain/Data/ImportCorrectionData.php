<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ImportCorrectionData extends Data
{
    /** @param array<string, string>|null $column_mapping */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, string> | null')]
        public ?array $column_mapping,
    ) {}
}
