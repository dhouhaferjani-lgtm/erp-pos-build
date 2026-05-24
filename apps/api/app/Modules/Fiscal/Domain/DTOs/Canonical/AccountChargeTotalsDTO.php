<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountChargeTotalsDTO
{
    public function __construct(
        public string $amountChargedToAccount,
        public string $grandTotalBeforeCharge,
        public string $subtotal,
        public string $total,
        public string $vatTotal,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amountChargedToAccount: FiscalPayloadArrayGuards::requireString($data, 'amount_charged_to_account'),
            grandTotalBeforeCharge: FiscalPayloadArrayGuards::requireString($data, 'grand_total_before_charge'),
            subtotal: FiscalPayloadArrayGuards::requireString($data, 'subtotal'),
            total: FiscalPayloadArrayGuards::requireString($data, 'total'),
            vatTotal: FiscalPayloadArrayGuards::requireString($data, 'vat_total'),
        );
    }
}
