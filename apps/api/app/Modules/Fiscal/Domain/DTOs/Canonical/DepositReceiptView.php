<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;

final readonly class DepositReceiptView
{
    public function __construct(
        public DepositReceiptPayload $payload,
        public DepositReceiptCustomerDTO $customer,
        public DepositReceiptPaymentDTO $payment,
    ) {}
}
