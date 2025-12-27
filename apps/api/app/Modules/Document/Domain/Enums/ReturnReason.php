<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Reasons for product returns.
 *
 * Used to categorize why customers are returning products.
 * Important for quality analysis and fraud detection.
 */
enum ReturnReason: string
{
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case CustomerRegret = 'customer_regret';
    case DamagedInTransit = 'damaged_in_transit';
    case Warranty = 'warranty';
    case Exchange = 'exchange';
    case Other = 'other';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Defective => 'Defective Product',
            self::WrongItem => 'Wrong Item Shipped',
            self::CustomerRegret => 'Customer Changed Mind',
            self::DamagedInTransit => 'Damaged During Shipping',
            self::Warranty => 'Warranty Return',
            self::Exchange => 'Exchange for Different Product',
            self::Other => 'Other Reason',
        };
    }

    /**
     * Check if this reason indicates a quality issue
     */
    public function isQualityIssue(): bool
    {
        return match ($this) {
            self::Defective, self::DamagedInTransit => true,
            default => false,
        };
    }
}
