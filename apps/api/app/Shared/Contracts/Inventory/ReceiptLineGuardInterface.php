<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Inventory;

/**
 * Cross-module read guard: which purchase-order line ids are referenced by any
 * goods-receipt line (regardless of receipt status — draft receipts also lock).
 * Scalar-only signature by design (deptrac: SharedContracts must not depend on
 * module Domain types).
 */
interface ReceiptLineGuardInterface
{
    /**
     * @param  list<string>  $poLineIds
     * @return list<string>
     */
    public function poLineIdsWithReceipts(array $poLineIds): array;
}
