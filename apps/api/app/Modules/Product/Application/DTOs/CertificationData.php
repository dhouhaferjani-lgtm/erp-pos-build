<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Certification;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CertificationData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $slug,
        public ?string $certifying_body,
        public ?string $logo_url,
        public ?string $verification_url,
        public bool $is_active,
        public int $display_order,
        public string $name,
        public ?string $description,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Certification $certification): self
    {
        return new self(
            id: $certification->id,
            type: $certification->type,
            slug: $certification->slug,
            certifying_body: $certification->certifying_body,
            logo_url: $certification->logo_url,
            verification_url: $certification->verification_url,
            is_active: $certification->is_active,
            display_order: $certification->display_order,
            name: $certification->name, // Uses HasTranslations trait accessor
            description: $certification->description, // Uses HasTranslations trait accessor
            created_at: $certification->created_at?->toIso8601String() ?? '',
            updated_at: $certification->updated_at?->toIso8601String(),
        );
    }
}
