<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration A2.
     *
     * 1. Adds the country-level rounding switches + denomination.
     * 2. Adds `pos_tolerance_enabled` — a SEPARATE kill-switch from the B2B
     *    `payment_tolerance_enabled` column, so disabling POS auto-accept can
     *    never switch off B2B invoice write-offs.
     * 3. UPSERTS the TN row with an explicit uuid id. The original
     *    `2025_12_10_100000` seed insert omitted `id` on a NOT-NULL uuid PK
     *    and only ran when `countries` was already populated (it is not, at
     *    tenant-migration time), so essentially NO tenant has a row today.
     *
     * OWNER-VISIBLE EFFECT (spec §7): inserting the TN row TIGHTENS the live
     * B2B tolerance ceiling from the 0.50 system default to the intended
     * 0.100. This is the intended correction; POS behavior stays off because
     * `pos_tolerance_enabled` and `cash_rounding_enabled` default to false.
     */
    public function up(): void
    {
        if (! Schema::hasTable('country_payment_settings')) {
            return;
        }

        Schema::table('country_payment_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_enabled')) {
                $table->boolean('cash_rounding_enabled')->default(false);
            }
            if (! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')) {
                $table->decimal('cash_rounding_denomination', 15, 4)->nullable();
            }
            if (! Schema::hasColumn('country_payment_settings', 'pos_tolerance_enabled')) {
                $table->boolean('pos_tolerance_enabled')->default(false);
            }
        });

        // The FK country_payment_settings.country_code -> countries.code means
        // the row can only exist once the country lookup is populated. Skip
        // silently otherwise; CountryPaymentSettingsSeeder (wired into the
        // real provisioning path, after CountriesSeeder) fills the gap.
        if (! Schema::hasTable('countries')) {
            return;
        }

        if (! DB::table('countries')->where('code', 'TN')->exists()) {
            return;
        }

        $existing = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $now = now();

        // The ceilings AND `payment_tolerance_enabled` are PINNED per spec §4.2
        // (this is the intended spec §7 tightening, rewritten on every re-run).
        // `cash_rounding_enabled` / `pos_tolerance_enabled` / a non-NULL
        // denomination are operator state and are never overwritten.
        $pinned = [
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'updated_at' => $now,
        ];

        if ($existing === null) {
            DB::table('country_payment_settings')->insert(array_merge($pinned, [
                'id' => (string) Str::uuid(),
                'country_code' => 'TN',
                'pos_tolerance_enabled' => false,
                'cash_rounding_enabled' => false,
                'cash_rounding_denomination' => '0.0500',
                'created_at' => $now,
            ]));

            return;
        }

        // Only BACKFILL the denomination when it has never been set — an operator
        // who chose 0.1000 keeps 0.1000 across re-runs of tenants:migrate. The
        // two rounding switches are absent from $pinned for the same reason.
        if (($existing->cash_rounding_denomination ?? null) === null) {
            $pinned['cash_rounding_denomination'] = '0.0500';
        }

        DB::table('country_payment_settings')->where('country_code', 'TN')->update($pinned);
    }

    public function down(): void
    {
        if (! Schema::hasTable('country_payment_settings')) {
            return;
        }

        Schema::table('country_payment_settings', function (Blueprint $table): void {
            $table->dropColumn(['cash_rounding_enabled', 'cash_rounding_denomination', 'pos_tolerance_enabled']);
        });
    }
};
