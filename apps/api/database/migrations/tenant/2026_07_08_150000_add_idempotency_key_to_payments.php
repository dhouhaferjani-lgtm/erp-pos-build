<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 16b (spine Wave D, HIGH-7): request-level idempotency for payment +
 * multipayment creation.
 *
 * `PaymentController::store()` mints a fresh `Payment` UUID on every request
 * with no client-supplied dedup key. A lost-response retry (client never saw
 * the 201, or a network timeout) creates a SECOND payment row — and since
 * Task 16 keys the treasury movement's `sourceId` on `$payment->id`, a second
 * payment means a second movement and the balance moves twice for what was,
 * from the client's perspective, one payment.
 *
 * `idempotency_key` lets the controller detect the retry BEFORE creating
 * anything: a client-supplied `Idempotency-Key` header (or `idempotency_key`
 * body field) is persisted on the payment row; a repeat request with the same
 * key short-circuits to the existing row.
 *
 * Nullable + partial unique index (not a plain unique column): every payment
 * created before this migration — and every future payment whose caller does
 * not supply a key — has `idempotency_key = NULL`. A full unique index would
 * reject the second, third, ... NULL row (Postgres/SQLite unique indexes
 * treat NULL as any other value for uniqueness EXCEPT that some engines
 * consider NULLs distinct — do not rely on that; use an explicit partial
 * index instead so only KEYED rows are constrained).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable()->after('refund_request_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX payments_idempotency_key_uniq '.
                'ON payments (company_id, idempotency_key) '.
                'WHERE idempotency_key IS NOT NULL'
            );
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite supports partial unique indexes via CREATE UNIQUE INDEX … WHERE.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS payments_idempotency_key_uniq '.
                'ON payments (company_id, idempotency_key) '.
                'WHERE idempotency_key IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS payments_idempotency_key_uniq');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
    }
};
