<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum EquivalenceType: string
{
    case Generic = 'generic';
    case Therapeutic = 'therapeutic';
    case BrandAlt = 'brand_alt';
}
