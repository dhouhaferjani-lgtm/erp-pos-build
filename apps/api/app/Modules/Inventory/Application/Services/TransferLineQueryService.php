<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockTransferLine;
use App\Shared\Contracts\TransferLineReader;
use App\Shared\DTOs\TransferLineDTO;

final class TransferLineQueryService implements TransferLineReader
{
    public function linesForTransfer(string $tenantId, string $companyId, string $transferId): array
    {
        $lines = StockTransferLine::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('transfer_id', $transferId)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (StockTransferLine $line): TransferLineDTO => new TransferLineDTO(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $line->quantity,
            ))
            ->all();

        return array_values($lines);
    }
}
