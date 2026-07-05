<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

/**
 * Three-way vs two-way AP matching mode for procurement policies.
 *
 * Three-way: price matching uses receipt-line accrual basis (PO ↔ GR ↔ bill).
 * Two-way:   price matching uses the PO line's contractual unit_price, while
 *            receipt quantity, GR-IR accrual, and FIFO 408 clearing still apply.
 */
enum MatchMode: string
{
    case TwoWay = 'two_way';
    case ThreeWay = 'three_way';
}
