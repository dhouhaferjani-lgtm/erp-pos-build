<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;

final readonly class AccountChargeView
{
    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<array<string, mixed>>  $vatBreakdown
     */
    public function __construct(
        public AccountChargePayload $payload,
        public AccountChargeCustomerDTO $customer,
        public AccountChargeBalanceSnapshotDTO $localBalanceSnapshot,
        public AccountChargeCreditDecisionDTO $creditDecision,
        public AccountChargeTermsDTO $chargeTerms,
        public AccountChargeTotalsDTO $totals,
        public SellerDTO $seller,
        public ?BuyerDTO $buyer,
        public array $lineItems,
        public array $vatBreakdown,
    ) {}
}
