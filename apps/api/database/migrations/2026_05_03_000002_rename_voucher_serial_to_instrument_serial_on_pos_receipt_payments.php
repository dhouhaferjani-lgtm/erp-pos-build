<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 21 (spec §3.3): pos_receipt_payments column rename + discriminator.
 *
 * Changes:
 *   1. Rename `voucher_serial` → `instrument_serial`.
 *      The original column recorded restaurant-voucher (ticket-restaurant) serials.
 *      The rename generalises the column so it can hold any payment-instrument serial
 *      (store voucher code, restaurant voucher serial, gift-card serial, etc.).
 *
 *   2. Add `instrument_type` VARCHAR(32) NULL with a CHECK constraint.
 *      Allowed values: store_voucher, restaurant_voucher, gift_card, none.
 *      NULL means "no instrument" (cash, card, etc.).
 *
 *   3. Backfill existing rows: any row where instrument_serial IS NOT NULL gets
 *      instrument_type = 'restaurant_voucher' because the original column meant
 *      "ticket-restaurant serial" — preserve that meaning in the discriminator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->renameColumn('voucher_serial', 'instrument_serial');
            $table->string('instrument_type', 32)->nullable()->after('instrument_serial');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE pos_receipt_payments '
                .'ADD CONSTRAINT pos_receipt_payments_instrument_type_check '
                ."CHECK (instrument_type IS NULL OR instrument_type IN ('store_voucher', 'restaurant_voucher', 'gift_card', 'none'))"
            );
        }

        // Backfill: rows that already have a serial are restaurant-voucher rows
        // (the original meaning of the column before the rename).
        DB::table('pos_receipt_payments')
            ->whereNotNull('instrument_serial')
            ->update(['instrument_type' => 'restaurant_voucher']);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipt_payments DROP CONSTRAINT IF EXISTS pos_receipt_payments_instrument_type_check');
        }

        Schema::table('pos_receipt_payments', function (Blueprint $table) {
            $table->dropColumn('instrument_type');
            $table->renameColumn('instrument_serial', 'voucher_serial');
        });
    }
};
