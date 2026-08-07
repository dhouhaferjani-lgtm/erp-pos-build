<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Shared\Contracts\Partner\PartnerReferenceSource;

/**
 * Answers "is this partner still referenced anywhere?" for the delete guard.
 *
 * This class owns NO table names. Every owning module declares its own
 * partner-referencing tables through an `App\Shared\Contracts\Partner\
 * PartnerReferenceSource` implementation and tags it on the container; this
 * counter merges what they report. Adding a table is therefore a change in
 * the module that owns it, and stays covered by that module's tests.
 *
 * BUG-007 (2026-08-06): `PartnerController::destroy` had no business guard
 * at all, so an admin could soft-delete a partner that still carried
 * invoices, payments or POS receipts. Because the partner is SOFT-deleted,
 * the database FKs never fire — RESTRICT never refuses, SET NULL never
 * nulls, CASCADE never cascades — and the referencing rows silently keep
 * pointing at a row that no longer surfaces anywhere.
 *
 * SCOPE (2026-08-07, lane R2-S). The original guard covered three tables of
 * the ~26 columns that reference `partners.id`; the gap is now closed for
 * every table where a lingering row can still ACT on the partner (see the
 * per-module sources). Four groups are DELIBERATELY not counted, and the
 * reasons are recorded here so nobody "fixes" them by accident:
 *
 *  1. `partner_bank_accounts`, `party_contacts`, `partner_price_lists` —
 *     all `cascadeOnDelete`. The schema declares them owned BY the partner;
 *     they describe it rather than record anything it took part in, and a
 *     hard delete removes them automatically. Counting them would make
 *     every fully-configured partner permanently undeletable while
 *     protecting nothing. (`pos_customer_aliases` is also CASCADE but IS
 *     counted — see `PosPartnerReferenceSource` for why it is not
 *     descriptive configuration.)
 *  2. `fiscal_events.partner_id` — the append-only fiscal journal OF the
 *     transactions already counted (`pos_receipts` / `documents`). It can
 *     never be the sole reference, so counting it would only duplicate a
 *     block that already fires, under a key an operator cannot act on.
 *  3. `customer_history_searches.partner_id` — an append-only PII audit log
 *     of a cashier SEARCHING for a customer. The partner is the object of
 *     the search, not a participant in a record; the rows are never
 *     deletable, so counting them would permanently block any partner who
 *     was ever looked up.
 *  4. `catalog_cart_items.preferred_supplier_partner_id` — a transient
 *     per-cart supplier preference (SET NULL, no financial or audit
 *     weight). An abandoned cart must not veto a supplier's deletion.
 *
 * CONNECTION TIMING — do not "simplify" this away. Sources must resolve
 * their connection at QUERY time, never at construction time: Laravel
 * builds `PartnerController` (and transitively this counter and every
 * tagged source) during `Route::gatherMiddleware()` /
 * `controllerMiddleware()`, which runs BEFORE the route's middleware
 * pipeline (`ResolveTenancy` -> Stancl `tenancy()->initialize()` ->
 * `DatabaseTenancyBootstrapper`) has swapped `database.default` from
 * `central` to the tenant DB. The original implementation
 * constructor-injected `Illuminate\Database\ConnectionInterface`, was
 * therefore pinned to `central` — which has none of these tables — and
 * `DELETE /api/v1/partners/{id}` 500'd on every call, unconditionally.
 * `DeletePartnerTest` could not catch it: `actingAs()` sets the
 * authenticated user directly and never sends a real bearer token through
 * `ResolveTenancy`'s bearer branch (the ONLY branch that flips the
 * connection in single-schema test mode), so the swap never happens
 * in-process and both the broken and fixed code read the same connection.
 * `PartnerReferenceCounterConnectionTimingTest` is the regression that CAN
 * see it; `TableBackedPartnerReferenceSource` is where the rule is enforced
 * for every source at once.
 */
final class PartnerReferenceCounter
{
    /**
     * @param  iterable<PartnerReferenceSource>  $sources  Tagged via
     *                                                     `app->tagged(PartnerReferenceSource::class)` in
     *                                                     `PartnerServiceProvider`. An empty set is a valid
     *                                                     runtime state (it means "nothing blocks").
     */
    public function __construct(
        private readonly iterable $sources,
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
        $counts = [];

        foreach ($this->sources as $source) {
            foreach ($source->countPartnerReferences($partnerId) as $table => $count) {
                // Summed rather than overwritten: two modules reporting the
                // same table name must not silently mask each other.
                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
