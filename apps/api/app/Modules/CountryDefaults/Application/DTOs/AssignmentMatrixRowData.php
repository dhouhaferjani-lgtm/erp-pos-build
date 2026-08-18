<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AssignmentMatrixRowData extends Data
{
    public function __construct(
        public readonly string $country_code,
        public readonly string $name,
        public readonly bool $pinned,
        public readonly TemplateDomain $domain,
        public readonly ?string $assignment_id,
        public readonly ?string $template_id,
        public readonly ?TemplateSummaryData $template,
    ) {}
}
