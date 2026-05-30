<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Services;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use App\Modules\Uom\Domain\Exceptions\IncompatibleUnitsException;
use App\Shared\Domain\QuantityScale;

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

        /** @var numeric-string $quantityBc */
        $quantityBc = $quantity;
        /** @var numeric-string $fromFactor */
        $fromFactor = $fromUnit->conversion_factor;
        /** @var numeric-string $toFactor */
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

        /** @var numeric-string $fromFactor */
        $fromFactor = $fromUnit->conversion_factor;
        /** @var numeric-string $toFactor */
        $toFactor = $toUnit->conversion_factor;

        return bcdiv($fromFactor, $toFactor, 10);
    }

    /**
     * Round value according to rounding method using bcmath (no float intermediary).
     */
    private function round(string $value, int $decimalPlaces, RoundingMethod $method): string
    {
        $methodStr = match ($method) {
            RoundingMethod::HalfUp => QuantityScale::HALF_UP,
            RoundingMethod::Floor => QuantityScale::FLOOR,
            RoundingMethod::Ceil => QuantityScale::CEIL,
        };

        return QuantityScale::round($value, $decimalPlaces, $methodStr);
    }
}
