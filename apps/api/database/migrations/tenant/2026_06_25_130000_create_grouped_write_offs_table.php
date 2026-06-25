<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3a — idempotency ledger for atomic multi-lot (grouped) write-offs.
 *
 * One row per ACCEPTED grouped write-off request, keyed by a client-supplied
 * idempotency_key. The UNIQUE (tenant_id, idempotency_key) index is the race
 * backstop: two concurrent first-time requests carrying the same key cannot
 * both insert — the loser hits the unique violation, rolls back its stock
 * mutations, and replays the winner's persisted result.
 *
 * `result` holds the serialized GroupedWriteOffResult (movement ids + per-lot
 * cost snapshot). It is JSONB-shaped and is (de)serialized exclusively through
 * the GroupedWriteOffResult DTO — never read as a loose array by callers.
 *
 * db-per-tenant note: each tenant owns its own database, so idempotency keys
 * are already physically partitioned per tenant; tenant_id is retained in the
 * composite unique index for defense-in-depth and to keep the single-connection
 * compat test suite honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grouped_write_offs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('location_id');
            $table->string('idempotency_key');
            $table->string('reason');
            $table->json('result');
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grouped_write_offs');
    }
};
