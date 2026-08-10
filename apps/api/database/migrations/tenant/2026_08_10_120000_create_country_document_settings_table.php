<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DPA Wave 3 / M5 (D-27) — the DOCUMENT-LANE country policy family.
 *
 * `country_payment_settings` is the treasury lane's seeded country home;
 * `country_inventory_settings` is the valuation lane's. Neither is the right
 * home for *"may a definitive goods invoice precede delivery?"*, which is a
 * document/invoicing rule — so this table is declared as **the** seeded home for
 * document-lane country policy, with `pre_delivery_invoicing_policy` as its
 * first column and every future document-lane country setting **extending it**
 * rather than minting another table (precedent:
 * `2026_07_12_100600_add_instrument_alert_days_to_country_payment_settings.php`,
 * `2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php`).
 *
 * Structural copy of `2025_12_10_100000_create_country_payment_settings_table.php`.
 *
 * The CHECK admits BOTH policy values on purpose: `allow` is refused in code
 * (`PreDeliveryInvoicingPolicyResolver`) until 472/419 deferred-revenue posting
 * exists, and admitting it here means enabling it later needs **no DDL on a live
 * tenant database**. Same three-layer shape as D-14's `periodic`.
 *
 * Unattended-safe: additive, idempotent, guarded per object.
 */
return new class extends Migration
{
    private const POLICY_VALUES = "'require_delivery_first', 'allow'";

    public function up(): void
    {
        if (! Schema::hasTable('country_document_settings')) {
            Schema::create('country_document_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('country_code', 2);
                $table->foreign('country_code')->references('code')->on('countries');

                // Wave 3 D-18′: the pre-delivery invoicing policy. NOT NULL with a
                // fail-closed default — a row that exists but says nothing must
                // still require delivery.
                $table->string('pre_delivery_invoicing_policy', 32)
                    ->default('require_delivery_first');

                $table->timestamps();
                $table->unique('country_code');
            });
        }

        // The company-level override (the M2 idiom): NULL = "inherit the country
        // default". No backfill — every existing company inherits.
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'pre_delivery_invoicing_policy')) {
                $table->string('pre_delivery_invoicing_policy', 32)->nullable();
            }
        });

        $this->addPolicyChecks();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_pre_delivery_invoicing_policy_valid');
            DB::statement('ALTER TABLE country_document_settings DROP CONSTRAINT IF EXISTS country_document_settings_policy_valid');
        }

        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'pre_delivery_invoicing_policy')) {
                $table->dropColumn('pre_delivery_invoicing_policy');
            }
        });

        Schema::dropIfExists('country_document_settings');
    }

    private function addPolicyChecks(): void
    {
        // SQLite cannot ALTER … ADD CONSTRAINT; the enum + resolver carry the
        // invariant there, exactly as the discount-policy migration does.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE country_document_settings DROP CONSTRAINT IF EXISTS country_document_settings_policy_valid'
        );
        DB::statement(
            'ALTER TABLE country_document_settings ADD CONSTRAINT country_document_settings_policy_valid '
            .'CHECK (pre_delivery_invoicing_policy IN ('.self::POLICY_VALUES.'))'
        );

        DB::statement(
            'ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_pre_delivery_invoicing_policy_valid'
        );
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_pre_delivery_invoicing_policy_valid '
            .'CHECK (pre_delivery_invoicing_policy IS NULL OR pre_delivery_invoicing_policy IN ('.self::POLICY_VALUES.'))'
        );
    }
};
