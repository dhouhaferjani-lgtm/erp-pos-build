<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TemplateValidationErrorData extends Data
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly string $code,
        #[LiteralTypeScriptType('Record<string, string>')]
        public readonly array $parameters,
    ) {}
}
