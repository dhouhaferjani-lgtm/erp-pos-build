<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AssignmentMatrixMetaData extends Data
{
    public function __construct(
        public readonly string $catalog_version,
    ) {}
}
