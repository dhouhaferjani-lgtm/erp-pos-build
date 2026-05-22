<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class CreatePOSChargeJournalEntryCommand
{
    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $vatTotal
     * @param  numeric-string  $total
     * @param  numeric-string  $transactionDiscountAmount
     * @param  list<array<string, mixed>>  $vatBreakdown
     * @param  list<array<string, mixed>>  $lineVatSummary
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $partnerId,
        public string $fiscalEventId,
        public string $accountChargeUuid,
        public string $businessDate,
        public string $currencyCode,
        public int $currencyScale,
        public string $subtotal,
        public string $vatTotal,
        public string $total,
        public string $transactionDiscountAmount,
        public array $vatBreakdown,
        public array $lineVatSummary,
        public ?string $actorUserId,
    ) {}
}
