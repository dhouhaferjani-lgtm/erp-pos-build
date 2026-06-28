<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Loyalty;

use Carbon\CarbonInterface;

/**
 * Immutable, primitives-only snapshot of a completed sale needed to credit
 * loyalty points. Crosses the POS→Loyalty module boundary (rule 6) — carries
 * no Loyalty or POS model, only scalars. Money is a numeric-string (rule 19).
 */
final readonly class SaleEarnContext
{
    public function __construct(
        public string $tenantId,
        public ?string $contactId,
        public ?string $partnerId,
        public string $currency,
        public string $sourceType,
        public string $sourceId,
        public ?string $receiptNumber,
        public CarbonInterface $postedAt,
        public string $earnBase,
    ) {}
}
