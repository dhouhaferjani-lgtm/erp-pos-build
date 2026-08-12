<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\DTOs;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
class TemplateSummaryData extends Data
{
    /** @param list<string>|null $certified_country_codes */
    public function __construct(
        public readonly string $id,
        public readonly TemplateDomain $domain,
        public readonly string $name,
        public readonly ?string $description,
        public readonly TemplateStatus $status,
        public readonly ?string $content_hash,
        public readonly ?string $standard_ref,
        #[TypeScriptType('string[]|null')]
        public readonly ?array $certified_country_codes,
        public readonly ?string $capability_registry_version,
        public readonly ?string $certified_by,
        public readonly ?string $published_at,
        public readonly ?string $cloned_from_id,
    ) {}

    public static function fromModel(AdminTemplate $template): self
    {
        return new self(
            id: $template->id,
            domain: $template->domain,
            name: $template->name,
            description: $template->description,
            status: $template->status,
            content_hash: $template->content_hash,
            standard_ref: $template->standard_ref,
            certified_country_codes: self::countryCodes($template->certified_country_codes),
            capability_registry_version: $template->capability_registry_version,
            certified_by: $template->certified_by,
            published_at: $template->published_at?->toAtomString(),
            cloned_from_id: $template->cloned_from_id,
        );
    }

    public static function fromNullableModel(?AdminTemplate $template): ?self
    {
        return $template === null ? null : self::fromModel($template);
    }

    /** @param array<array-key, mixed>|null $codes
     * @return list<string>|null
     */
    private static function countryCodes(?array $codes): ?array
    {
        if ($codes === null) {
            return null;
        }

        $normalized = [];
        foreach ($codes as $code) {
            if (is_string($code)) {
                $normalized[] = $code;
            }
        }

        return $normalized;
    }
}
