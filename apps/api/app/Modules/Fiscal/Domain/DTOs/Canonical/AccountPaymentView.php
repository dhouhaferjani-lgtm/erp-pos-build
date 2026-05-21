<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\AccountPaymentPayload;

final readonly class AccountPaymentView
{
    public function __construct(
        public AccountPaymentPayload $payload,
        public AccountPaymentCustomerDTO $customer,
        public AccountPaymentPaymentDTO $payment,
        public AccountPaymentBalanceSnapshotDTO $localBalanceSnapshot,
        public AccountPaymentStalenessDTO $staleness,
        public SellerDTO $seller,
    ) {}
}
