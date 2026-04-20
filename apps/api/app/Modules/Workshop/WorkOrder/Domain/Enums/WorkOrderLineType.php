<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * Classification of a WorkOrderLine. Drives polymorphic ref population rules
 * (Part → product_id, Labor/Sublet → service_id, BundleHeader → service_bundle_id)
 * and pricing behavior (CoreCharge is a refundable deposit, CoreReturn flips it).
 */
enum WorkOrderLineType: string
{
    case Part = 'part';
    case Labor = 'labor';
    case CoreCharge = 'core_charge';
    case CoreReturn = 'core_return';
    case Sublet = 'sublet';
    case EnvironmentalFee = 'environmental_fee';
    case MiscFee = 'misc_fee';
    case BundleHeader = 'bundle_header';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
