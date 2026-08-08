<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * A line's product resolves to a different tenant than the document header
 * (DPA V7 / D14a).
 *
 * Refused BEFORE any advisory lock is taken. ProductCostLock keys on the literal
 * "wac:{tenantId}:{companyId}:{productId}", so if post()'s up-front sorted
 * acquire used the header's tenant while the nested per-line acquire resolved a
 * different one, the two would hash DIFFERENT strings — the deadlock defence
 * would evaporate while the architecture test stayed green, because it only
 * greps the POSITION of `costLock->acquire(`, never the key.
 */
class LineTenantMismatchException extends DomainException
{
    public function __construct(
        public readonly string $productId,
        public readonly string $expectedTenantId,
        public readonly string $foundTenantId,
    ) {
        parent::__construct(
            "Product {$productId} belongs to tenant {$foundTenantId}, not {$expectedTenantId}."
        );
    }
}
