<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\MarginSource;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EffectiveMargins extends Data
{
    public function __construct(
        public readonly string $target_margin,
        public readonly string $minimum_margin,
        public readonly MarginSource $target_source,
        public readonly ?int $target_source_category_id,
        public readonly MarginSource $minimum_source,
        public readonly ?int $minimum_source_category_id,
        public readonly bool $minimum_clamped,
    ) {}
}
