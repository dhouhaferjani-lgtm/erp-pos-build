<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final class VariantIndexNames
{
    public const string TENANT_SKU_UNIQUE = 'product_variants_tenant_sku_unique';

    public const string COMPANY_SKU_UNIQUE = 'product_variants_company_sku_unique';

    public const string TENANT_BARCODE_UNIQUE = 'product_variants_tenant_barcode_unique';
}
