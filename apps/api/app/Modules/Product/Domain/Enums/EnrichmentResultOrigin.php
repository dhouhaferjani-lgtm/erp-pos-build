<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum EnrichmentResultOrigin: string
{
    case Initial = 'initial';
    case CuratedUpdate = 'curated_update';
}
