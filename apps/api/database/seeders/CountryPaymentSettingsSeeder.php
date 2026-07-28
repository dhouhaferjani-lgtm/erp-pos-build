<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Greenfield self-healing for `country_payment_settings` (spec §4.2).
 *
 * The original table migration's inline seed insert is broken (no `id` on a
 * NOT-NULL uuid PK) and only ran when `countries` was already populated —
 * which it is not at tenant-migration time. This seeder is hooked into
 * TenantInitializationService::seedReferenceData() (the REAL provisioning
 * path) AFTER CountriesSeeder, plus ProductionSeeder.
 *
 * The tolerance ceilings AND `payment_tolerance_enabled` are pinned per spec
 * §4.2 — they are rewritten on every run. `cash_rounding_enabled`,
 * `pos_tolerance_enabled` and a non-NULL `cash_rounding_denomination` are
 * operator state and are never overwritten; a NULL denomination is backfilled
 * once. New rows are always created with both rounding switches DISABLED.
 */
class CountryPaymentSettingsSeeder extends Seeder
{
    /**
     * @var list<array{country_code: string, payment_tolerance_percentage: string, max_payment_tolerance_amount: string, cash_rounding_denomination: string|null}>
     */
    private const DEFAULTS = [
        [
            'country_code' => 'TN',
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'cash_rounding_denomination' => '0.0500',
        ],
        [
            'country_code' => 'FR',
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.5000',
            'cash_rounding_denomination' => null,
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('country_payment_settings') || ! Schema::hasTable('countries')) {
            return;
        }
        if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')) {
            return;
        }

        $now = now();

        foreach (self::DEFAULTS as $definition) {
            if (! DB::table('countries')->where('code', $definition['country_code'])->exists()) {
                continue;
            }

            $existing = DB::table('country_payment_settings')
                ->where('country_code', $definition['country_code'])
                ->first();

            $pinned = [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => $definition['payment_tolerance_percentage'],
                'max_payment_tolerance_amount' => $definition['max_payment_tolerance_amount'],
                'updated_at' => $now,
            ];

            if ($existing === null) {
                DB::table('country_payment_settings')->insert(array_merge($pinned, [
                    'id' => (string) Str::uuid(),
                    'country_code' => $definition['country_code'],
                    'cash_rounding_enabled' => false,
                    'pos_tolerance_enabled' => false,
                    'cash_rounding_denomination' => $definition['cash_rounding_denomination'],
                    'created_at' => $now,
                ]));

                continue;
            }

            // BACKFILL only — never overwrite a denomination an operator chose.
            // The two rounding switches are absent from $pinned for the same reason.
            if ($definition['cash_rounding_denomination'] !== null
                && ($existing->cash_rounding_denomination ?? null) === null) {
                $pinned['cash_rounding_denomination'] = $definition['cash_rounding_denomination'];
            }

            DB::table('country_payment_settings')
                ->where('country_code', $definition['country_code'])
                ->update($pinned);
        }
    }
}
