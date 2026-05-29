<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnit(string $code, int $decimalPlaces): Unit
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        return Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => $code,
            'decimal_places' => $decimalPlaces,
            'rounding_method' => RoundingMethod::Floor,
            'is_system' => true,
        ]);
    }

    public function test_applies_canonical_decimal_places_to_existing_units(): void
    {
        // Existing units at the migration default (2) — should be corrected.
        $kg = $this->makeUnit('kg', 2);
        $ea = $this->makeUnit('EA', 2);
        $l = $this->makeUnit('l', 2);

        (new UnitSeeder)->run();

        $this->assertSame(3, $kg->fresh()->decimal_places, 'KG should be scale 3');
        $this->assertSame(0, $ea->fresh()->decimal_places, 'EA should be scale 0');
        $this->assertSame(3, $l->fresh()->decimal_places, 'L should be scale 3');

        // rounding_method is left untouched — an operator's deliberate choice
        // (here Floor) must be preserved, not clobbered to the canonical default.
        $this->assertSame(RoundingMethod::Floor, $kg->fresh()->rounding_method);
    }

    public function test_does_not_override_user_customised_decimal_places(): void
    {
        // A unit whose decimal_places was deliberately changed away from the
        // migration default (2) must NOT be clobbered by the seeder.
        $kg = $this->makeUnit('kg', 5);

        (new UnitSeeder)->run();

        $this->assertSame(5, $kg->fresh()->decimal_places, 'Customised scale must be preserved');
    }

    public function test_is_idempotent(): void
    {
        $kg = $this->makeUnit('kg', 2);

        (new UnitSeeder)->run();
        $afterFirst = $kg->fresh()->decimal_places;

        (new UnitSeeder)->run();
        $afterSecond = $kg->fresh()->decimal_places;

        $this->assertSame(3, $afterFirst);
        $this->assertSame($afterFirst, $afterSecond);
        // No duplicate rows created for the same code.
        $this->assertSame(1, Unit::query()->whereRaw('LOWER(code) = ?', ['kg'])->count());
    }

    public function test_matches_unit_code_case_insensitively(): void
    {
        // UomSeeder uses lowercase codes; the canonical map uses uppercase.
        $g = $this->makeUnit('g', 2);
        $mm = $this->makeUnit('mm', 2);

        (new UnitSeeder)->run();

        $this->assertSame(0, $g->fresh()->decimal_places, 'G should be scale 0');
        $this->assertSame(0, $mm->fresh()->decimal_places, 'MM should be scale 0');
    }
}
