<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // fiscal_hash was already made nullable by 2026_03_02_300000.
        // Make chain_sequence nullable so pending_seal rows can be inserted
        // before the fiscal hash chain is assigned.
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->integer('chain_sequence')->nullable()->change();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The pos_receipts_sequence constraint enforces chain_sequence > 0 on non-NULL values.
        // Drop it so that NULL (pending_seal state) is accepted; re-add with IS NULL guard.
        DB::statement(
            'ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_sequence'
        );
        DB::statement(
            'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_sequence '.
            'CHECK (chain_sequence IS NULL OR chain_sequence > 0)'
        );

        // Drop any existing fiscal_status CHECK constraints (added by offline-sync migration
        // or a previous run) and replace with a superset that includes 'pending_seal'.
        DB::statement(
            'DO $$ '.
            'DECLARE r record; '.
            'BEGIN '.
            '  FOR r IN '.
            '    SELECT conname FROM pg_constraint '.
            "    WHERE conrelid = 'pos_receipts'::regclass ".
            "    AND contype = 'c' ".
            "    AND pg_get_constraintdef(oid) LIKE '%fiscal_status%' ".
            '  LOOP '.
            "    EXECUTE 'ALTER TABLE pos_receipts DROP CONSTRAINT ' || quote_ident(r.conname); ".
            '  END LOOP; '.
            'END $$'
        );

        DB::statement(
            'ALTER TABLE pos_receipts '.
            'ADD CONSTRAINT pos_receipts_fiscal_status_check '.
            "CHECK (fiscal_status IN ('pending_seal', 'fiscalized', 'voided', 'pending_sync', 'synced', 'sync_failed'))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_fiscal_status_check'
            );
            DB::statement(
                'ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_sequence'
            );
            DB::statement(
                'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_sequence CHECK (chain_sequence > 0)'
            );
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->integer('chain_sequence')->nullable(false)->change();
        });
    }
};
