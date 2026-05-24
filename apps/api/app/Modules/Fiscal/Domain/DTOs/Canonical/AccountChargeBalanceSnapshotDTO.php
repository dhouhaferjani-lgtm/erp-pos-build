<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountChargeBalanceSnapshotDTO
{
    public function __construct(
        public string $balanceUpdatedAt,
        public string $chargeAmount,
        public string $creditBalanceBefore,
        public string $netBalanceBefore,
        public string $projectedCreditBalanceAfter,
        public string $projectedNetBalanceAfter,
        public string $projectedReceivableBalanceAfter,
        public string $receivableBalanceBefore,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            balanceUpdatedAt: FiscalPayloadArrayGuards::requireString($data, 'balance_updated_at'),
            chargeAmount: FiscalPayloadArrayGuards::requireString($data, 'charge_amount'),
            creditBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'credit_balance_before'),
            netBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'net_balance_before'),
            projectedCreditBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_credit_balance_after'),
            projectedNetBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_net_balance_after'),
            projectedReceivableBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_receivable_balance_after'),
            receivableBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'receivable_balance_before'),
        );
    }
}
