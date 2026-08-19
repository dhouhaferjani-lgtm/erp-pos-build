<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

final class InventoryGlSourceTypes
{
    /** @var list<string> */
    public const array ALL = [
        'inventory_exit',
        'inventory_entry',
        'inventory_shrinkage',
        'batch_write_off',
        'batch_write_off_reversal',
    ];
}
