<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Brand;

/**
 * Outcome of resolving a platform brand payload to a local brand row.
 * shouldPushMapping is true only when this call changed local state.
 */
final readonly class BrandResolution
{
    public function __construct(
        public Brand $brand,
        public bool $shouldPushMapping,
    ) {}
}
