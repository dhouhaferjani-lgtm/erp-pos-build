<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\HealthClaim;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class HealthClaimData extends Data
{
    /**
     * @param  array<string, bool>|null  $country_restrictions
     */
    public function __construct(
        public string $id,
        public string $claim_type,
        public string $slug,
        public string $regulatory_status,
        public ?string $efsa_reference,
        public ?string $fda_reference,
        public ?array $country_restrictions,
        public bool $requires_disclaimer,
        public string $claim,
        public ?string $disclaimer_text,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(HealthClaim $healthClaim): self
    {
        return new self(
            id: $healthClaim->id,
            claim_type: $healthClaim->claim_type,
            slug: $healthClaim->slug,
            regulatory_status: $healthClaim->regulatory_status,
            efsa_reference: $healthClaim->efsa_reference,
            fda_reference: $healthClaim->fda_reference,
            country_restrictions: $healthClaim->country_restrictions,
            requires_disclaimer: $healthClaim->requires_disclaimer,
            claim: $healthClaim->claim ?? '', // Uses HasTranslations trait accessor
            disclaimer_text: $healthClaim->disclaimer_text, // Uses HasTranslations trait accessor
            created_at: $healthClaim->created_at?->toIso8601String() ?? '',
            updated_at: $healthClaim->updated_at?->toIso8601String(),
        );
    }
}
