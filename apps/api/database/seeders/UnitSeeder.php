<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Seed canonical per-unit quantity-precision defaults.
 *
 * Part of the precision & scale-drift remediation: the `units.decimal_places`
 * column (default 2 from the create migration) was effectively dormant. This
 * seeder applies the canonical display/validation precision for each well-known
 * unit code, plus a default rounding method.
 *
 * IDEMPOTENCY & UPDATE-ONLY DESIGN
 * ================================
 * The plan called for `updateOrCreate` keyed on unit code. In practice units
 * carry a NOT-NULL `category_id` foreign key and belong to a `UnitCategory`
 * structure that this seeder does not own (categories + base units are created
 * by UomSeeder / per-tenant flows). Creating a Unit row here with no real
 * category would produce orphaned, mis-categorised data. So this seeder is
 * deliberately UPDATE-ONLY: it matches existing units by code (case-insensitively,
 * because UomSeeder stores lowercase codes while the canonical map is uppercase)
 * and corrects their precision. It is fully idempotent (re-running is a no-op
 * once values match) and creates no rows.
 *
 * SAFETY: `decimal_places` is only overwritten when the stored value is still the
 * migration default (2). A value the operator deliberately customised away from 2
 * is preserved untouched.
 */
class UnitSeeder extends Seeder
{
    /**
     * Migration default — only this value is treated as "uncustomised" and safe
     * to overwrite with the canonical scale.
     */
    private const MIGRATION_DEFAULT_DECIMAL_PLACES = 2;

    /**
     * Canonical per-unit-code quantity precision.
     *
     * @var array<string, int>
     */
    private const DECIMAL_PLACES = [
        'EA' => 0,
        'PCS' => 0,
        'BOX' => 0,
        'CTN' => 0,
        'PACK' => 0,
        'KG' => 3,
        'G' => 0,
        'MG' => 0,
        'L' => 3,
        'ML' => 0,
        'M' => 3,
        'CM' => 0,
        'MM' => 0,
        'M3' => 4,
        'M2' => 4,
        'HR' => 2,
        'MIN' => 0,
        'KWH' => 3,
    ];

    public function run(): void
    {
        foreach (self::DECIMAL_PLACES as $code => $decimalPlaces) {
            /** @var Collection<int, Unit> $units */
            $units = Unit::query()
                ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                ->get();

            foreach ($units as $unit) {
                $changes = [];

                // Only correct the precision if it is still the migration default.
                if ($unit->decimal_places === self::MIGRATION_DEFAULT_DECIMAL_PLACES
                    && $decimalPlaces !== self::MIGRATION_DEFAULT_DECIMAL_PLACES
                ) {
                    $changes['decimal_places'] = $decimalPlaces;
                }

                // Canonicalise the rounding method to HalfUp for these standard units.
                if ($unit->rounding_method !== RoundingMethod::HalfUp) {
                    $changes['rounding_method'] = RoundingMethod::HalfUp;
                }

                if ($changes !== []) {
                    $unit->update($changes);
                }
            }
        }
    }
}
