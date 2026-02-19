<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Services;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use App\Modules\Uom\Domain\Exceptions\IncompatibleUnitsException;

class UnitConversionService
{
    /**
     * Convert quantity from one unit to another
     *
     * @throws IncompatibleUnitsException if units are from different categories
     */
    public function convert(
        string $quantity,
        Unit $fromUnit,
        Unit $toUnit
    ): string {
        // Check compatibility
        if ($fromUnit->category_id !== $toUnit->category_id) {
            throw new IncompatibleUnitsException(
                "Cannot convert between {$fromUnit->name} and {$toUnit->name}: different categories"
            );
        }

        // Convert to base unit first, then to target unit
        // Formula: target_qty = source_qty × (source_factor / target_factor)

        $quantityBc = $quantity;
        $fromFactor = $fromUnit->conversion_factor;
        $toFactor = $toUnit->conversion_factor;

        // Use bcmath for precision
        $inBaseUnits = bcmul($quantityBc, $fromFactor, 10);
        $result = bcdiv($inBaseUnits, $toFactor, 10);

        // Apply rounding and decimal places
        return $this->round($result, $toUnit->decimal_places, $toUnit->rounding_method);
    }

    /**
     * Check if two units can be converted
     */
    public function canConvert(Unit $fromUnit, Unit $toUnit): bool
    {
        return $fromUnit->category_id === $toUnit->category_id;
    }

    /**
     * Get conversion factor between two units
     */
    public function getConversionFactor(Unit $fromUnit, Unit $toUnit): string
    {
        if (! $this->canConvert($fromUnit, $toUnit)) {
            return '0';
        }

        return bcdiv($fromUnit->conversion_factor, $toUnit->conversion_factor, 10);
    }

    /**
     * Round value according to rounding method
     */
    private function round(string $value, int $decimalPlaces, RoundingMethod $method): string
    {
        $multiplier = bcpow('10', (string) $decimalPlaces, 0);
        $scaled = bcmul($value, $multiplier, 10);

        $rounded = match ($method) {
            RoundingMethod::HalfUp => round((float) $scaled),
            RoundingMethod::Floor => floor((float) $scaled),
            RoundingMethod::Ceil => ceil((float) $scaled),
        };

        return bcdiv((string) $rounded, $multiplier, $decimalPlaces);
    }
}
