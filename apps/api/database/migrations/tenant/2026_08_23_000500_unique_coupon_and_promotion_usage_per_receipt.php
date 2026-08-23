<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane Q-4 (Session B, 2026-08-23) — one usage row per (coupon|promotion, receipt).
 *
 * `coupon_usages` and `promotion_usages` shipped with plain indexes only, so a
 * POS sync retry of a single sealed receipt inserted a SECOND usage row and
 * permanently inflated `coupons.use_count` / `promotions.usage_count`. The
 * application-tier fix (lock + transaction + idempotent replay in
 * CouponApplicationService::recordUsage) needs a DB-level backstop, because the
 * counter is the only thing standing between a `max_uses = 1` promo and an
 * unbounded giveaway.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ MIGRATION-BEARING — MANDATORY PRE-FLIGHT DUPLICATE SCAN
 * ─────────────────────────────────────────────────────────────────────────────
 * These are UNIQUE indexes over pre-existing rows. Any tenant database that
 * already holds a duplicated (parent_id, receipt_id) pair will ABORT here, and
 * because `tenants:migrate` walks tenant databases in sequence, one dirty
 * tenant stops the fleet mid-roll. Run the census below against EVERY tenant
 * database BEFORE deploying, and remediate every row it returns.
 *
 * Per-tenant census (run in each `tenant_<uuid>` database):
 *
 *   SELECT 'coupon_usages'   AS usage_table,
 *          coupon_id         AS parent_id,
 *          receipt_id,
 *          COUNT(*)          AS duplicate_count,
 *          MIN(used_at)      AS first_used_at,
 *          MAX(used_at)      AS last_used_at
 *   FROM coupon_usages
 *   GROUP BY coupon_id, receipt_id
 *   HAVING COUNT(*) > 1
 *   UNION ALL
 *   SELECT 'promotion_usages',
 *          promotion_id,
 *          receipt_id,
 *          COUNT(*),
 *          MIN(used_at),
 *          MAX(used_at)
 *   FROM promotion_usages
 *   GROUP BY promotion_id, receipt_id
 *   HAVING COUNT(*) > 1
 *   ORDER BY duplicate_count DESC;
 *
 * Zero rows fleet-wide ⇒ safe to roll. Any row ⇒ the duplicates are replayed
 * sync artefacts: keep the earliest usage row per pair, delete the rest, and
 * decrement the parent counter by the number of rows deleted (the counter was
 * incremented once per duplicated insert). Both remediations are data fixes and
 * are deliberately NOT performed here — this migration refuses to guess which
 * of two money-bearing rows is the real one.
 *
 * BOTH tables are scanned BEFORE either index is created (gate r1 F-8), so a
 * tenant that is dirty on both learns both remediations from a single abort
 * instead of discovering the second one on the retry. PostgreSQL runs each
 * migration in a schema transaction, so an abort leaves no index behind.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{index: string, parent: string}>
     */
    private const TARGETS = [
        'coupon_usages' => [
            'index' => 'uniq_coupon_usages_coupon_receipt',
            'parent' => 'coupon_id',
        ],
        'promotion_usages' => [
            'index' => 'uniq_promotion_usages_promotion_receipt',
            'parent' => 'promotion_id',
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Phase 1 — scan EVERY target before creating ANY index, so a tenant
        // dirty on both tables gets both remediations from one abort.
        $problems = [];
        foreach (self::TARGETS as $table => $target) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $problem = $this->describeDuplicates($table, $target['parent'], $target['index']);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Cannot create the coupon/promotion usage-per-receipt unique indexes. '
                .implode(' ', $problems)
                .' Run the per-tenant census in this migration docblock and remediate every '
                .'listed table before retrying.'
            );
        }

        // Phase 2 — DDL.
        foreach (self::TARGETS as $table => $target) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, receipt_id)',
                $target['index'],
                $table,
                $target['parent'],
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $target) {
            DB::statement('DROP INDEX IF EXISTS '.$target['index']);
        }
    }

    private function describeDuplicates(string $table, string $parentColumn, string $index): ?string
    {
        $duplicates = DB::select(
            "SELECT {$parentColumn} AS parent_id, receipt_id, COUNT(*) AS duplicate_count
             FROM {$table}
             GROUP BY {$parentColumn}, receipt_id
             HAVING COUNT(*) > 1"
        );

        if ($duplicates === []) {
            return null;
        }

        $first = $duplicates[0];

        return sprintf(
            '[%s] %s holds %d duplicated (%s, receipt_id) pair(s); '
            .'first offender %s = %s, receipt_id = %s (%s rows).',
            $index,
            $table,
            count($duplicates),
            $parentColumn,
            $parentColumn,
            (string) $first->parent_id,
            (string) $first->receipt_id,
            (string) $first->duplicate_count,
        );
    }
};
