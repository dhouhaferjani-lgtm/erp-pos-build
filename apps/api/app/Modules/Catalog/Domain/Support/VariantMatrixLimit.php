<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Support;

final class VariantMatrixLimit
{
    /** Maximum total variants a single generate call may produce for a product. */
    public const MAX_VARIANTS_PER_GENERATE = 200;
}
