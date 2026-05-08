<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * api.platform-integration cluster — Finding F closure.
 *
 * Adds a DB UNIQUE constraint on `billing_payments(provider,
 * provider_payment_id)` so cross-tenant collisions on Stripe's
 * `pi_*` namespace (and any future provider's) are structurally
 * impossible. Pairs with the StripeWebhookController callsite
 * change that adds `where('provider', PaymentProviderCode::Stripe)`
 * + `->first()` → `->sole()` at lines 449, 473, 502.
 *
 * Pre-existing schema (from migration
 * 2025_12_16_100004_create_billing_payments_table.php:81) only had a
 * composite INDEX, not a UNIQUE — Stripe's per-account-globally-unique
 * `pi_*` contract was implicitly trusted, but no DB-level enforcement
 * existed. The (provider, provider_payment_id) tuple form is correct:
 * different providers have independent ID namespaces and a contrived
 * collision must not be a UNIQUE violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-migration safety check: fail LOUD if duplicates exist on
        // the (provider, provider_payment_id) tuple. Stripe's contract
        // guarantees `pi_*` uniqueness within Stripe; duplicates would
        // indicate a webhook deduplication bug (Finding E in
        // 2026-05-07-scheduled-jobs-cross-cluster-observations.md is
        // exactly the missing event-id idempotency that could surface
        // as duplicates).
        // Count duplicate (provider, provider_payment_id) tuples via a
        // grouped subquery — PostgreSQL's COUNT(DISTINCT) takes a single
        // expression, so we use a subquery aggregating to one row per
        // unique tuple and sum the over-count.
        $duplicates = DB::selectOne(
            'SELECT COALESCE(SUM(c - 1), 0) AS dup FROM (
                SELECT COUNT(*) AS c
                FROM billing_payments
                WHERE provider_payment_id IS NOT NULL
                GROUP BY provider, provider_payment_id
                HAVING COUNT(*) > 1
            ) AS over_counts'
        );
        $dup = (int) ($duplicates->dup ?? 0);

        if ($dup !== 0) {
            throw new \RuntimeException(
                'Cannot apply UNIQUE constraint to billing_payments(provider, provider_payment_id): '
                .$dup.' duplicate tuple(s) exist. '
                .'Triage required: identify whether these are webhook redeliveries (Finding E missing event-id idempotency), '
                .'legacy data, or legitimate collisions, and remediate before re-running this migration. '
                .'Query to inspect: SELECT provider, provider_payment_id, COUNT(*) FROM billing_payments WHERE provider_payment_id IS NOT NULL GROUP BY 1, 2 HAVING COUNT(*) > 1;'
            );
        }

        Schema::table('billing_payments', function (Blueprint $table) {
            // Drop the old composite INDEX before adding the UNIQUE
            // (the UNIQUE replaces the index — `where('provider', $p)
            // ->where('provider_payment_id', $id)` queries hit the
            // unique constraint's index just as efficiently). The old
            // index was named via Laravel's auto-naming convention.
            $table->dropIndex(['provider', 'provider_payment_id']);
            $table->unique(['provider', 'provider_payment_id'], 'billing_payments_provider_provider_payment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropUnique('billing_payments_provider_provider_payment_id_unique');
            // Restore the original composite INDEX (matching the shape
            // of the dropped index — column order + name conventions
            // per migration 2025_12_16_100004_create_billing_payments_table.php:81).
            $table->index(['provider', 'provider_payment_id']);
        });
    }
};
