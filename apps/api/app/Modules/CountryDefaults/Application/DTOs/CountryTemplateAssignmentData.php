<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CountryTemplateAssignmentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $country_code,
        public readonly TemplateDomain $domain,
        public readonly string $template_id,
        public readonly ?TemplateSummaryData $template,
    ) {}

    public static function fromModel(CountryTemplateAssignment $assignment): self
    {
        return new self(
            id: $assignment->id,
            country_code: $assignment->country_code,
            domain: $assignment->domain,
            template_id: $assignment->template_id,
            template: $assignment->relationLoaded('template')
                ? TemplateSummaryData::fromNullableModel($assignment->template)
                : null,
        );
    }
}
