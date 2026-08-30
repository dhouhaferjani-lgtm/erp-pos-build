<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\ProductIdentityInputData;
use App\Shared\DTOs\ProductIdentityResolutionData;

interface ProductResolverInterface
{
    public function resolve(
        string $tenantId,
        string $companyId,
        ?string $sku,
        ?string $barcode,
        string $name,
    ): ProductIdentityResolutionData;

    /**
     * Resolve a chunk with at most one master-data query per identity arm.
     *
     * @param  list<ProductIdentityInputData>  $inputs
     * @return array<string, ProductIdentityResolutionData>
     */
    public function resolveMany(string $tenantId, string $companyId, array $inputs): array;
}
