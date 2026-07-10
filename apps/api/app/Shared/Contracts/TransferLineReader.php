<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\TransferLineDTO;

interface TransferLineReader
{
    /** @return list<TransferLineDTO> */
    public function linesForTransfer(string $tenantId, string $companyId, string $transferId): array;
}
