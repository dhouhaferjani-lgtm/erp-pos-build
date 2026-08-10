<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Domain\CountryDocumentDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Greenfield self-healing for `country_document_settings` (Wave 3 T25a / D-27).
 *
 * Structural copy of {@see CountryPaymentSettingsSeeder}, including its
 * fail-closed `Schema::hasTable` guards: this seeder is hooked into
 * `TenantInitializationService::seedReferenceData()` — the REAL provisioning
 * path — plus `ProductionSeeder`, and both can run against a database where the
 * M5 migration has not yet been applied.
 *
 * The policy is **PINNED**: it is rewritten on every run. It is a compliance
 * control derived from statute, not operator state — a tenant that wants a
 * different value sets the COMPANY override, which this seeder never touches.
 * (Contrast `cash_rounding_denomination`, which the payment seeder back-fills
 * once and then leaves alone precisely because it IS operator state.)
 */
class CountryDocumentSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('country_document_settings') || ! Schema::hasTable('countries')) {
            return;
        }

        $now = now();

        foreach (CountryDocumentDefaults::all() as $countryCode => $definition) {
            if (! DB::table('countries')->where('code', $countryCode)->exists()) {
                // Same degrade-to-warning as the payment seeder: if this runs
                // before CountriesSeeder, or the code is missing from the
                // countries lookup, the country is skipped. It is NOT silently
                // dangerous — a tenant with no row resolves to the system default,
                // which is the same `require_delivery_first` — but the operator
                // should know the seeded row is absent. `$this->command` is null
                // when the seeder is instantiated directly (as
                // TenantInitializationService does), hence the nullsafe call.
                /** @phpstan-ignore-next-line nullsafe.neverNull — `$this->command` IS null when the seeder is instantiated directly (TenantInitializationService does exactly that) rather than through `$this->call()`. */
                $this->command?->warn(sprintf(
                    'CountryDocumentSettingsSeeder: skipping %s — no matching row in countries. '
                    .'That country will resolve to the system default policy.',
                    $countryCode,
                ));

                continue;
            }

            $pinned = [
                'pre_delivery_invoicing_policy' => $definition['pre_delivery_invoicing_policy']->value,
                'updated_at' => $now,
            ];

            $exists = DB::table('country_document_settings')
                ->where('country_code', $countryCode)
                ->exists();

            if (! $exists) {
                DB::table('country_document_settings')->insert(array_merge($pinned, [
                    'id' => (string) Str::uuid(),
                    'country_code' => $countryCode,
                    'created_at' => $now,
                ]));

                continue;
            }

            DB::table('country_document_settings')
                ->where('country_code', $countryCode)
                ->update($pinned);
        }
    }
}
