<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Contracts;

use App\Modules\Workshop\Bundle\Domain\ValueObjects\ComponentProductRef;

/**
 * Read-through contract wrapping the Product module for bundle components.
 * Returns null when the referenced product is missing, deleted, or
 * otherwise not resolvable.
 */
interface ProductResolverInterface
{
    public function findForBundleComponent(string $productId): ?ComponentProductRef;
}
