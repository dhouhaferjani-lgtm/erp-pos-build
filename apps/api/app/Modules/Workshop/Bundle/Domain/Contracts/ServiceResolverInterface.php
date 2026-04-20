<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Contracts;

use App\Modules\Workshop\Bundle\Domain\ValueObjects\ComponentServiceRef;

/**
 * Read-through contract wrapping the Service (labor-catalog) module for
 * bundle components. Returns null when the referenced service is missing.
 */
interface ServiceResolverInterface
{
    public function findForBundleComponent(string $serviceId): ?ComponentServiceRef;
}
