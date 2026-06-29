<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum BrandSource: string
{
    case User = 'user';
    case Enriched = 'enriched';
}
