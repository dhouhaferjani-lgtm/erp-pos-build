<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Domain\CountryInventoryDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Greenfield self-healing for `country_inventory_settings` (DPA Wave 3, T8).
 *
 * Copy of {@see CountryPaymentSettingsSeeder}, including its fail-closed
 * `Schema::hasTable` guards, because it runs in the same places and under the
 * same constraint: it may execute before `countries` exists, before its own
 * table exists (a tenant database mid-migration), or twice.
 *
 * Hooked into `TenantInitializationService::seedReferenceData()` immediately
 * after the payment-settings seeder — i.e. AFTER `CountriesSeeder` — and into
 * `ProductionSeeder`.
 *
 * The valuation mode is PINNED: rewritten on every run. It is not operator
 * state — the country row IS the jurisdiction default, and a tenant that wants
 * something else sets the company override.
 */
class CountryInventorySettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('country_inventory_settings') || ! Schema::hasTable('countries')) {
            return;
        }

        $now = now();

        // Lane P-1: the GL-posting column arrives in a LATER migration than this
        // seeder's own table, and the seeder runs on tenant databases that are
        // mid-migration. Same fail-closed posture as the hasTable guard above —
        // write the column only once it exists.
        $hasGlPostingColumn = Schema::hasColumn('country_inventory_settings', 'count_correction_gl_posting_enabled');

        foreach (CountryInventoryDefaults::all() as $countryCode => $mode) {
            if (! DB::table('countries')->where('code', $countryCode)->exists()) {
                // Same degrade-to-no-op as CountryPaymentSettingsSeeder: a
                // tenant without this country seeded will have no
                // country_inventory_settings row and will fall to the SYSTEM
                // default at the resolver.
                //
                // The signal goes to the LOG, not to `$this->command->warn()`
                // like the sibling seeder. Two reasons, and the first is the
                // real one: the path that actually matters is
                // TenantInitializationService, which instantiates this seeder
                // DIRECTLY (`new CountryInventorySettingsSeeder`), so
                // `$this->command` is unset there and a console warn is written
                // to nobody. Second, `Seeder::$command` is docblocked
                // non-nullable, so BOTH runtime-correct idioms — `?->` and
                // `isset()` — are rejected by PHPStan level 8, and suppressing
                // that is not allowed here.
                Log::warning(sprintf(
                    'CountryInventorySettingsSeeder: skipping %s — no matching row in countries. '
                    .'That country will resolve to the system default instead of its own row.',
                    $countryCode,
                ));

                continue;
            }

            $existing = DB::table('country_inventory_settings')
                ->where('country_code', $countryCode)
                ->first();

            $pinned = [
                'inventory_valuation_mode' => $mode->value,
                'updated_at' => $now,
            ];

            if ($hasGlPostingColumn) {
                // PINNED like the valuation mode, and for the same reason: the
                // country row IS the jurisdiction default (lane P-1, owner
                // ruling 2026-08-25). A tenant that wants something else sets
                // the company override, which this seeder never touches.
                //
                // A country absent from the posting map falls back to the
                // system default rather than inventing a jurisdiction answer —
                // `CountCorrectionGlPostingResolver` makes that visible as
                // `source: system`.
                $countryDefault = CountryInventoryDefaults::countCorrectionGlPostingForCountry($countryCode);

                if ($countryDefault !== null) {
                    $pinned['count_correction_gl_posting_enabled'] = $countryDefault;
                }
            }

            if ($existing === null) {
                DB::table('country_inventory_settings')->insert($pinned + [
                    'id' => (string) Str::uuid(),
                    'country_code' => $countryCode,
                    'created_at' => $now,
                ]);

                continue;
            }

            DB::table('country_inventory_settings')
                ->where('country_code', $countryCode)
                ->update($pinned);
        }
    }
}
