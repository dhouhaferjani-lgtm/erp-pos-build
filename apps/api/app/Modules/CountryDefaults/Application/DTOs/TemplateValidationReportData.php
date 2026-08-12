<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class TemplateValidationReportData extends Data
{
    /**
     * @param  list<string>  $scope
     * @param  DataCollection<int, TemplateValidationErrorData>|array<int, TemplateValidationErrorData>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        #[TypeScriptType('string[]')]
        public readonly array $scope,
        #[DataCollectionOf(TemplateValidationErrorData::class)]
        public readonly DataCollection|array $errors,
    ) {}
}
