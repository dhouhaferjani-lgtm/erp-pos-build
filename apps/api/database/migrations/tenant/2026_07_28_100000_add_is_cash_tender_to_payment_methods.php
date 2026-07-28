<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration A1.
     *
     * ONE cash-ness predicate for every layer (spec §4.1): a tender leg is
     * cash iff its payment method carries `is_cash_tender = true`. The
     * invariant `is_cash_tender = true => code = 'CASH'` (EXACT,
     * case-sensitive) is enforced on write by PaymentMethodController; this
     * migration establishes it for existing rows by normalizing the code at
     * the same time it sets the flag.
     *
     * Self-guarding: the table may not exist on a partially-provisioned
     * tenant DB, and the column may already exist on a re-run.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->boolean('is_cash_tender')
                    ->default(false)
                    ->after('is_physical');
            });
        }

        // Backfill + code normalization in one statement so no row can end
        // up flagged with a non-canonical code.
        DB::statement(
            "UPDATE payment_methods SET is_cash_tender = true, code = 'CASH' WHERE UPPER(code) = 'CASH'"
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'COMMENT ON COLUMN payment_methods.is_cash_tender IS '.
                "'Canonical cash-ness predicate for POS rounding/tolerance. TRUE implies code = ''CASH'' exactly.'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_methods') || ! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            return;
        }

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('is_cash_tender');
        });
    }
};
