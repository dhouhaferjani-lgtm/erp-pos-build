<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Counts rows in THREE specific tables that still point at a partner:
 * `documents`, `payments` and `pos_receipts`.
 *
 * SCOPE — read this before relying on it. This is deliberately NOT a complete
 * "is this partner referenced anywhere" check. `partner_id` exists on roughly
 * fourteen tenant tables; the ones NOT covered here include `journal_lines`,
 * `vouchers`, `workshop_work_orders`, `scheduling_appointments`, `pos_orders`,
 * `withholding_certificates`, `promotion_usages`, `coupon_usages`, `vehicles`,
 * `partner_price_lists`, `buyer_seller_mappings`, `platform_supplier_mappings`
 * and `pos_customer_aliases`. An Otospex partner with an open work order, or a
 * partner holding a voucher, still deletes today. Widening the sweep is
 * ticketed (`docs/superpowers/tickets/2026-08-06-l6-partners-followups.md`).
 *
 * BUG-007: `PartnerController::destroy` had no business guard at all, so an
 * admin could soft-delete a partner that still carried invoices, payments or
 * POS receipts. Because the partner is SOFT-deleted, the database FKs never
 * fire (`pos_receipts.partner_id` is `nullOnDelete`, `payments`/`documents` are
 * `restrict`) — the rows silently keep pointing at a row that no longer
 * surfaces anywhere.
 *
 * **Live-verification fix (2026-08-06).** This class originally
 * constructor-injected `Illuminate\Database\ConnectionInterface`. That
 * resolves to a CONCRETE connection object at the moment the container
 * builds it — and Laravel builds `PartnerController` (which builds this
 * class as one of its dependencies) during `Route::gatherMiddleware()` /
 * `controllerMiddleware()`, which runs BEFORE the route's own middleware
 * pipeline (`ResolveTenancy` -> Stancl `tenancy()->initialize()` ->
 * `DatabaseTenancyBootstrapper`) has swapped `database.default` from
 * `central` to the tenant DB. Every query this class issued was pinned to
 * `central`, which has no `documents`/`payments`/`pos_receipts` tables —
 * `DELETE /api/v1/partners/{id}` 500'd on every call, unconditionally.
 * `DeletePartnerTest` never caught it: `actingAs()` sets the authenticated
 * user directly and never sends a real bearer token through the request
 * pipeline, so `ResolveTenancy`'s bearer branch (the ONLY branch that
 * flips the connection in single-schema test mode) never runs, and no
 * "wrong connection" divergence is observable in-process — see the
 * `WHY actingAs() cannot catch this` note on
 * `PartnerReferenceCounterConnectionTimingTest`.
 *
 * The fix: inject `Illuminate\Database\DatabaseManager` instead and call
 * `->connection()` (no args) INSIDE each query, at call time. Per
 * `DatabaseManager::connection()` / `getDefaultConnection()`,
 * `database.default` is re-read from config on every call — so a query
 * issued after `ResolveTenancy` has run reflects the CURRENT tenant
 * connection, regardless of what the default was when this object was
 * constructed. Same pattern as
 * `Treasury\Application\Services\InstrumentAccountResolver`.
 *
 * NOTE for future readers: `Fiscal\...\OutboxIngestor` (which reads
 * `pos_terminals`) still constructor-injects `ConnectionInterface`, same as
 * this class did before this fix. It is NOT a sanctioned counter-example —
 * it is the SAME defect class, just not yet confirmed to manifest for that
 * controller's route. See the repo-wide audit ticket
 * (`docs/superpowers/tickets/2026-08-06-l6-partners-followups.md`).
 *
 * Cross-module reads use the query builder against table names rather than
 * importing Document/Payment/Receipt models, so the Partner module keeps no
 * compile-time dependency on those modules. That trade buys a missing
 * compile-time edge at the price of an UNGUARDED SCHEMA dependency, so per
 * the gate ruling this counter must not grow further without a
 * `Shared/Contracts` reader owned by the module that owns the table.
 */
final class PartnerReferenceCounter
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Reference counts per table, omitting tables with no references.
     *
     * An empty array means the partner is safe to delete.
     *
     * @return array<string, int>
     */
    public function countFor(string $partnerId): array
    {
        $counts = [
            // Quotes, orders, invoices, credit notes, delivery notes. Documents
            // are soft-deleted, and a soft-deleted document must not block.
            'documents' => $this->db->connection()->table('documents')
                ->where('partner_id', $partnerId)
                ->whereNull('deleted_at')
                ->count(),
            // Treasury payments — these carry the partner's open balance.
            // The table has no soft deletes.
            'payments' => $this->db->connection()->table('payments')
                ->where('partner_id', $partnerId)
                ->count(),
            // Fiscal POS receipts — immutable by design (NF525), never deleted.
            'pos_receipts' => $this->db->connection()->table('pos_receipts')
                ->where('partner_id', $partnerId)
                ->count(),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
