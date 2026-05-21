<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountPaymentBalanceSnapshotDTO
{
    public function __construct(
        public string $receivableBalanceBefore,
        public string $creditBalanceBefore,
        public string $netBalanceBefore,
        public string $paymentAmount,
        public string $projectedReceivableBalanceAfter,
        public string $projectedCreditBalanceAfter,
        public string $projectedNetBalanceAfter,
        public string $balanceUpdatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            receivableBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'receivable_balance_before'),
            creditBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'credit_balance_before'),
            netBalanceBefore: FiscalPayloadArrayGuards::requireString($data, 'net_balance_before'),
            paymentAmount: FiscalPayloadArrayGuards::requireString($data, 'payment_amount'),
            projectedReceivableBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_receivable_balance_after'),
            projectedCreditBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_credit_balance_after'),
            projectedNetBalanceAfter: FiscalPayloadArrayGuards::requireString($data, 'projected_net_balance_after'),
            balanceUpdatedAt: FiscalPayloadArrayGuards::requireString($data, 'balance_updated_at'),
        );
    }
}
