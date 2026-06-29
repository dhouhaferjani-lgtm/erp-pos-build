<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Brand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class BrandData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $country_of_origin,
        public ?string $website_url,
        public bool $is_active,
    ) {}

    public static function fromModel(Brand $brand): self
    {
        return new self(
            id: $brand->id,
            name: $brand->name,
            slug: $brand->slug,
            country_of_origin: $brand->country_of_origin,
            website_url: $brand->website_url,
            is_active: $brand->is_active,
        );
    }
}
