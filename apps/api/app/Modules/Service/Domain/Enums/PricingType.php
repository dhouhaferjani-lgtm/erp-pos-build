<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Enums;

/**
 * Service pricing types.
 *
 * - flat_rate: Fixed price per service (e.g., "Oil Change: 45 TND")
 * - hourly: Price based on time spent (e.g., "50 TND/hour")
 * - percentage: Price as percentage of another amount (e.g., "10% markup on parts")
 */
enum PricingType: string
{
    case FlatRate = 'flat_rate';
    case Hourly = 'hourly';
    case Percentage = 'percentage';

    /**
     * Get all enum values as strings.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get human-readable label for the pricing type.
     */
    public function label(): string
    {
        return match ($this) {
            self::FlatRate => 'Flat Rate',
            self::Hourly => 'Hourly',
            self::Percentage => 'Percentage',
        };
    }
}
