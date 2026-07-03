<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Enums;

enum CashMovementSourceType: string
{
    case CustomerPayment = 'customer_payment';
    case Payment = 'payment';
    case PosReceipt = 'pos_receipt';
    case SupplierPayment = 'supplier_payment';
}
