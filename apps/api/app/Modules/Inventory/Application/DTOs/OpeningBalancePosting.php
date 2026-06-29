<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Carbon\CarbonInterface;

final readonly class OpeningBalancePosting
{
    /** @param array<int, OpeningBalanceLine> $lines */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $userId,
        public CarbonInterface $entryDate,
        public bool $isHistorical,
        public string $sourceType,
        public string $sourceId,
        public string $reference,
        public ?string $notes,
        public array $lines,
    ) {}
}
