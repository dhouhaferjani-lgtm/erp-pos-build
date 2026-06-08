<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use App\Modules\Catalog\Domain\Enums\AttributeDataType;

/**
 * Command to create a new product attribute (variant axis or informational).
 *
 * tenant_id is implicitly derived from the DB-per-tenant connection context; it
 * must still be supplied explicitly so the Application layer remains decoupled
 * from the Stancl helper.
 */
final readonly class CreateAttributeCommand
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $code,
        public readonly string $name,
        public readonly AttributeDataType $dataType,
        public readonly bool $isVariantAxis = true,
        public readonly int $displayOrder = 0,
    ) {}
}
