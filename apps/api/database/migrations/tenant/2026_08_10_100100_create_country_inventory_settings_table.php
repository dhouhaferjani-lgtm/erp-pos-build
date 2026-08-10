<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DPA Wave 3 · T8 / M3 — `country_inventory_settings`.
 *
 * Structural copy of `2025_12_10_100000_create_country_payment_settings_table.php`,
 * with ONE deliberate difference: this migration seeds NOTHING.
 *
 * That table's inline seed insert is broken (no `id` on a NOT-NULL uuid PK) and
 * only fired when `countries` happened to be populated — which it is not at
 * tenant-migration time. `CountryInventorySettingsSeeder`, hooked into the real
 * provisioning path, owns the rows instead.
 *
 * The `non_stocked_supplies_purpose` column an earlier draft proposed is
 * DEFERRED with the consumables lane (sub-wave 3G): an unused column invites a
 * wrong read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('country_inventory_settings')) {
            return;
        }

        Schema::create('country_inventory_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('country_code', 2);
            $table->foreign('country_code')->references('code')->on('countries');

            $table->string('inventory_valuation_mode', 16)->default('perpetual');

            $table->timestamps();
            $table->unique('country_code');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "ALTER TABLE country_inventory_settings ADD CONSTRAINT country_inventory_settings_mode_valid
             CHECK (inventory_valuation_mode IN ('perpetual', 'periodic'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('country_inventory_settings');
    }
};
