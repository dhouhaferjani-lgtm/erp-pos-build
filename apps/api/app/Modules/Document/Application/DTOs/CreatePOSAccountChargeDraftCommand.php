<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

final readonly class CreatePOSAccountChargeDraftCommand
{
    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $vatTotal
     * @param  numeric-string  $total
     * @param  numeric-string  $transactionDiscountAmount
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $payloadSnapshot
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
        public array $lineItems,
        public array $payloadSnapshot,
        public ?string $dueDate,
    ) {}
}
