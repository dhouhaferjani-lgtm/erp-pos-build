<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only migration: rewrite legacy free-form fuel_type / transmission strings
 * to valid enum values before Task 9 activates enum casts on the Vehicle model.
 *
 * Inline literal lists (not enum class references) so this migration stays
 * self-contained and survives future enum refactors without accidentally
 * promoting legacy values into validity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        // Valid enum values as of 2026-04-19 (matches App\Modules\Vehicle\Domain\Enums\*).
        $validFuelTypes = [
            'gasoline', 'diesel', 'electric', 'hybrid', 'plugin_hybrid',
            'lpg', 'cng', 'hydrogen', 'other',
        ];
        $validTransmissions = [
            'manual', 'automatic', 'semi_automatic', 'cvt', 'dual_clutch', 'other',
        ];

        /** @var list<string> $distinctFuel */
        $distinctFuel = DB::table('vehicles')
            ->whereNotNull('fuel_type')
            ->distinct()
            ->pluck('fuel_type')
            ->all();
        foreach ($distinctFuel as $value) {
            if (! in_array($value, $validFuelTypes, true)) {
                DB::table('vehicles')->where('fuel_type', $value)->update(['fuel_type' => 'other']);
            }
        }

        /** @var list<string> $distinctTrans */
        $distinctTrans = DB::table('vehicles')
            ->whereNotNull('transmission')
            ->distinct()
            ->pluck('transmission')
            ->all();
        foreach ($distinctTrans as $value) {
            if (! in_array($value, $validTransmissions, true)) {
                DB::table('vehicles')->where('transmission', $value)->update(['transmission' => 'other']);
            }
        }
    }

    public function down(): void
    {
        // No-op: we cannot restore arbitrary prior values. Re-running up() is idempotent.
    }
};
