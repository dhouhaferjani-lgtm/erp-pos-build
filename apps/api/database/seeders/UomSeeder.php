<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Illuminate\Database\Seeder;

class UomSeeder extends Seeder
{
    public function run(): void
    {
        // Weight Category
        $weightCategory = UnitCategory::create([
            'code' => 'weight',
            'name' => 'Weight',
            'description' => 'Mass measurement units',
            'is_system' => true,
            'is_active' => true,
        ]);

        $gram = Unit::create([
            'category_id' => $weightCategory->id,
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $weightCategory->update(['base_unit_id' => $gram->id]);

        Unit::create([
            'category_id' => $weightCategory->id,
            'code' => 'mg',
            'name' => 'Milligram',
            'symbol' => 'mg',
            'conversion_factor' => '0.001',
            'decimal_places' => 0,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $weightCategory->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 3,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $weightCategory->id,
            'code' => 'oz',
            'name' => 'Ounce',
            'symbol' => 'oz',
            'conversion_factor' => '28.3495',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $weightCategory->id,
            'code' => 'lb',
            'name' => 'Pound',
            'symbol' => 'lb',
            'conversion_factor' => '453.592',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        // Volume Category
        $volumeCategory = UnitCategory::create([
            'code' => 'volume',
            'name' => 'Volume',
            'description' => 'Liquid and gas volume measurement units',
            'is_system' => true,
            'is_active' => true,
        ]);

        $milliliter = Unit::create([
            'category_id' => $volumeCategory->id,
            'code' => 'ml',
            'name' => 'Milliliter',
            'symbol' => 'mL',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $volumeCategory->update(['base_unit_id' => $milliliter->id]);

        Unit::create([
            'category_id' => $volumeCategory->id,
            'code' => 'cl',
            'name' => 'Centiliter',
            'symbol' => 'cL',
            'conversion_factor' => '10',
            'decimal_places' => 1,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $volumeCategory->id,
            'code' => 'l',
            'name' => 'Liter',
            'symbol' => 'L',
            'conversion_factor' => '1000',
            'decimal_places' => 3,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $volumeCategory->id,
            'code' => 'floz',
            'name' => 'Fluid Ounce',
            'symbol' => 'fl oz',
            'conversion_factor' => '29.5735',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        // Length Category
        $lengthCategory = UnitCategory::create([
            'code' => 'length',
            'name' => 'Length',
            'description' => 'Distance measurement units',
            'is_system' => true,
            'is_active' => true,
        ]);

        $millimeter = Unit::create([
            'category_id' => $lengthCategory->id,
            'code' => 'mm',
            'name' => 'Millimeter',
            'symbol' => 'mm',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $lengthCategory->update(['base_unit_id' => $millimeter->id]);

        Unit::create([
            'category_id' => $lengthCategory->id,
            'code' => 'cm',
            'name' => 'Centimeter',
            'symbol' => 'cm',
            'conversion_factor' => '10',
            'decimal_places' => 1,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $lengthCategory->id,
            'code' => 'm',
            'name' => 'Meter',
            'symbol' => 'm',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $lengthCategory->id,
            'code' => 'in',
            'name' => 'Inch',
            'symbol' => 'in',
            'conversion_factor' => '25.4',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        // Pieces Category
        $piecesCategory = UnitCategory::create([
            'code' => 'pieces',
            'name' => 'Pieces',
            'description' => 'Countable items',
            'is_system' => true,
            'is_active' => true,
        ]);

        $piece = Unit::create([
            'category_id' => $piecesCategory->id,
            'code' => 'pc',
            'name' => 'Piece',
            'symbol' => 'pcs',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $piecesCategory->update(['base_unit_id' => $piece->id]);

        Unit::create([
            'category_id' => $piecesCategory->id,
            'code' => 'pair',
            'name' => 'Pair',
            'symbol' => 'pair',
            'conversion_factor' => '2',
            'decimal_places' => 0,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $piecesCategory->id,
            'code' => 'doz',
            'name' => 'Dozen',
            'symbol' => 'doz',
            'conversion_factor' => '12',
            'decimal_places' => 0,
            'is_system' => true,
        ]);

        // Time Category (for service durations)
        $timeCategory = UnitCategory::create([
            'code' => 'time',
            'name' => 'Time',
            'description' => 'Duration units',
            'is_system' => true,
            'is_active' => true,
        ]);

        $minute = Unit::create([
            'category_id' => $timeCategory->id,
            'code' => 'min',
            'name' => 'Minute',
            'symbol' => 'min',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $timeCategory->update(['base_unit_id' => $minute->id]);

        Unit::create([
            'category_id' => $timeCategory->id,
            'code' => 'hr',
            'name' => 'Hour',
            'symbol' => 'hr',
            'conversion_factor' => '60',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        Unit::create([
            'category_id' => $timeCategory->id,
            'code' => 'day',
            'name' => 'Day',
            'symbol' => 'day',
            'conversion_factor' => '1440',
            'decimal_places' => 2,
            'is_system' => true,
        ]);

        // Apply canonical per-unit quantity-precision defaults. Idempotent and
        // update-only — corrects decimal_places/rounding_method for the units
        // created above without touching operator-customised values.
        (new UnitSeeder)->run();
    }
}
