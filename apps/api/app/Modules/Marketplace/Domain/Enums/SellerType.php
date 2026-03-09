<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Enums;

enum SellerType: string
{
    case ErpTenant = 'erp_tenant';
    case External = 'external';
    case Syneriva = 'syneriva';

    public function label(): string
    {
        return match ($this) {
            self::ErpTenant => 'ERP Tenant',
            self::External => 'External',
            self::Syneriva => 'Syneriva',
        };
    }
}
