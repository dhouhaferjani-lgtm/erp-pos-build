<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-1 (owner ruling 2026-08-25) — mirror the sealed `discount_allocated` onto
 * `pos_receipt_vat_details`.
 *
 * At `event_version = 5` a SALE_RECEIPT seals the taxable base NET of the
 * ticket-level remise, ventilated pro-rata per rate, and each `vat_breakdown[]`
 * row carries its own share of that remise. `pos_receipt_vat_details` is the
 * read model the DGI declaration reads (`EloquentVatDataRepository`), so the
 * share has to be recoverable from the row too — otherwise the only place the
 * ventilation exists is inside the canonical blob and no reconciliation, audit
 * or correcting entry can see it.
 *
 * NON-MIGRATION-BEARING in the risky sense: the column is NULLABLE with no
 * default, no backfill, and no CHECK. Every existing row keeps `NULL`, which is
 * the honest value — those receipts were sealed on the PRE-discount base and
 * never had a ventilation. "The remise was ventilated and this group got
 * nothing" (`0.000`) and "this version had no concept of ventilation" (`NULL`)
 * must stay distinguishable forever.
 *
 * Pre-flight census (per tenant DB) — expected to be a no-op, run it anyway:
 *
 *   SELECT count(*) FROM information_schema.columns
 *    WHERE table_name = 'pos_receipt_vat_details'
 *      AND column_name = 'discount_allocated';
 *   -- 0 => the column is new, the ADD COLUMN is a metadata-only operation on
 *   --      PostgreSQL 11+ (nullable, no default => no table rewrite).
 *
 * Scale matches its siblings after `2026_03_11_200000_widen_monetary_columns_to_scale_3`:
 * decimal(12, 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_receipt_vat_details', 'discount_allocated')) {
            return;
        }

        Schema::table('pos_receipt_vat_details', function (Blueprint $table): void {
            $table->decimal('discount_allocated', 12, 3)->nullable()->after('gross_amount');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'COMMENT ON COLUMN pos_receipt_vat_details.discount_allocated IS '
                ."'D-1: this rate group''s pro-rata share of the ticket-level remise (sealed at "
                .'SALE_RECEIPT event_version >= 5). NULL on rows projected from v1..v4 events, '
                ."which sealed the taxable base BEFORE the remise.'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_receipt_vat_details', 'discount_allocated')) {
            return;
        }

        Schema::table('pos_receipt_vat_details', function (Blueprint $table): void {
            $table->dropColumn('discount_allocated');
        });
    }
};
