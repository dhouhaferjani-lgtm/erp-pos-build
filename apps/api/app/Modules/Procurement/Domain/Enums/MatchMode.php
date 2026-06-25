<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

/**
 * Three-way vs two-way AP matching mode for procurement policies.
 *
 * Three-way: PO ↔ GR ↔ Supplier Bill must all align before payment.
 * Two-way:   PO ↔ Supplier Bill (no GR requirement). Used for service POs.
 */
enum MatchMode: string
{
    case TwoWay = 'two_way';
    case ThreeWay = 'three_way';
}
