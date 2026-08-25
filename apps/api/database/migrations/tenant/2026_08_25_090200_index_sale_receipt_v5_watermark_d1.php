<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-1 gate r1 finding 5 — index the forward-version watermark probe.
 *
 * `SaleReceiptForwardVersionGate::verdict()` runs on the INGEST HOT PATH for
 * every pre-v5 `SALE_RECEIPT` — i.e. every receipt from every not-yet-upgraded
 * terminal, which is all of them until the POS build ships. `fiscal_events`
 * carries `UNIQUE (tenant_id, company_id, terminal_id, chain_context,
 * sequence_number)` and four partial indexes, none of which covers
 * `event_type` or `event_version`, so the probe would range-scan every event
 * that chain has ever authored.
 *
 * This partial index is the honest expression of what the watermark IS: "has
 * this chain ever sealed a post-remise SALE_RECEIPT?". It indexes only the
 * v5+ sale rows — a handful per chain, and NONE at all before the cutover — so
 * it costs almost nothing to carry and turns the probe into a constant-time
 * existence check.
 *
 * NON-MIGRATION-BEARING: additive, no constraint, no backfill, no data change.
 * On PostgreSQL it is a partial index (`WHERE` clause); SQLite supports partial
 * indexes too, and the same statement is used there so the test drivers agree.
 * `CREATE INDEX IF NOT EXISTS` makes a re-run a no-op.
 *
 * Deliberately NOT `CONCURRENTLY`: Laravel runs migrations inside a
 * transaction, `CREATE INDEX CONCURRENTLY` cannot run in one, and the
 * predicate matches zero rows on every tenant at deploy time (no v5 receipt
 * exists yet), so the build takes a brief lock over an empty set.
 */
return new class extends Migration
{
    private const INDEX = 'fiscal_events_sale_receipt_v5_watermark_idx';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql' && $driver !== 'sqlite') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON fiscal_events '
            .'(tenant_id, company_id, terminal_id, chain_context) '
            ."WHERE event_type = 'SALE_RECEIPT' AND event_version >= 5"
        );

        if ($driver === 'pgsql') {
            DB::statement(
                'COMMENT ON INDEX '.self::INDEX.' IS '
                ."'D-1: serves SaleReceiptForwardVersionGate''s per-chain post-remise watermark probe on the "
                ."fiscal-event ingest path. Partial on the v5+ SALE_RECEIPT rows only.'"
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql' && $driver !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
