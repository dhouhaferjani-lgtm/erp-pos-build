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
        Schema::create('voucher_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();

            $table->uuid('voucher_id')->index();
            $table->foreign('voucher_id')->references('id')->on('vouchers');

            // VoucherEvent enum value
            $table->string('event', 32);

            // Positive on issuance, negative on redemption/reversal
            $table->decimal('amount', 20, 5);
            $table->char('currency', 3);

            // FK to pos_receipts — the sale that redeemed or credit note that issued
            $table->uuid('receipt_id')->nullable();

            // Terminal where the event happened
            $table->uuid('terminal_id')->nullable();

            // Who triggered the event
            $table->uuid('user_id');

            // GL journal entry for this event (non-null for events that hit the GL)
            $table->uuid('gl_journal_entry_id')->nullable();

            $table->uuid('authorized_by_user_id')->nullable();
            $table->string('policy_trigger')->nullable();

            // For Reversed event: FK to the ledger row being reversed
            $table->uuid('reverses_voucher_ledger_id')->nullable();

            $table->timestamp('occurred_at');

            // Append-only: created_at only, no updated_at
            $table->timestamp('created_at')->useCurrent();

            // Ledger projection indexes
            $table->index(['voucher_id', 'occurred_at'], 'voucher_ledger_voucher_occurred_idx');
            $table->index(['tenant_id', 'event', 'occurred_at'], 'voucher_ledger_tenant_event_occurred_idx');
        });

        // Self-referential FK is added AFTER table creation so the primary key
        // on `id` is guaranteed to exist first. Laravel's PostgreSQL grammar
        // emits `add primary key` AFTER `add foreign key` when both are declared
        // inside the same Schema::create() closure, which makes a same-table FK
        // fail on a fresh PostgreSQL migrate (SQLSTATE 42830: "no unique
        // constraint matching given keys"). Deferring it keeps the final schema
        // identical while making `migrate:fresh` work on PostgreSQL.
        Schema::table('voucher_ledger', function (Blueprint $table): void {
            $table->foreign('reverses_voucher_ledger_id')->references('id')->on('voucher_ledger');
        });

        // Enforce append-only semantics via PostgreSQL trigger.
        // Reuses the same pattern as pos_receipts immutability trigger.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_voucher_ledger_modification() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Cannot delete voucher ledger row % — append-only table.', OLD.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'Cannot update voucher ledger row % — append-only table.', OLD.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                COMMENT ON FUNCTION prevent_voucher_ledger_modification() IS
                    'Voucher ledger append-only enforcement: no UPDATE or DELETE ever allowed.';

                CREATE TRIGGER enforce_voucher_ledger_immutability
                    BEFORE UPDATE OR DELETE ON voucher_ledger
                    FOR EACH ROW EXECUTE FUNCTION prevent_voucher_ledger_modification();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS enforce_voucher_ledger_immutability ON voucher_ledger;
                DROP FUNCTION IF EXISTS prevent_voucher_ledger_modification();
            SQL);
        }

        Schema::dropIfExists('voucher_ledger');
    }
};
