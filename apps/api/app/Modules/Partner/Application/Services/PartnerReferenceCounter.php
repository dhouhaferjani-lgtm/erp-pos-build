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
 * per-module sources). The columns deliberately NOT counted are no longer
 * prose: they are `EXCLUDED_PARTNER_COLUMNS` below, and
 * `PartnerReferenceSchemaSweepTest` walks the live schema and fails on any
 * partner-shaped column that is neither declared by a source nor listed
 * there. A new FK-less PARTNER-SHAPED money column cannot ship unnoticed.
 * Two shapes escape BOTH nets (round-2 gate m-1): a partner reference that is
 * FK-less AND non-conventionally named (`supplier_id`, `client_id`,
 * `vendor_id`, ...), and a polymorphic anchor (`*_id` + `*_type`
 * discriminator, no FK — `loyalty_members.loyaltyable_id` is exactly this
 * shape and was covered by hand, not caught by the ratchet). Reviewers of new
 * migrations must check those two shapes manually.
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
     * Partner-shaped columns the delete guard deliberately does NOT count,
     * each with the reason it is safe to ignore.
     *
     * This is policy in code, not commentary: `PartnerReferenceSchemaSweepTest`
     * asserts that every `partner_id` / `*_partner_id` / `customer_id` /
     * `*_customer_id` column in the live schema is either declared by a
     * tagged `PartnerReferenceSource` or listed here — and that every key
     * here still exists and is not also declared. Adding a column to this
     * list is therefore an explicit, reviewable decision.
     *
     * @var array<string, string> `table.column` => why it is excluded
     */
    public const array EXCLUDED_PARTNER_COLUMNS = [
        // --- Cascade-owned descriptive child data -----------------------
        // The schema declares these owned BY the partner (cascadeOnDelete):
        // they describe it rather than record anything it took part in, and
        // a hard delete removes them automatically. Counting them would make
        // every fully-configured partner permanently undeletable while
        // protecting nothing. (`pos_customer_aliases` is also CASCADE but IS
        // counted — see PosPartnerReferenceSource for why it is not
        // descriptive configuration.)
        'partner_bank_accounts.partner_id' => 'cascadeOnDelete; the partner s own bank details, not a record it participated in',
        'partner_price_lists.partner_id' => 'cascadeOnDelete; the partner s own negotiated pricing, removed with it',
        // Found by the sweep's FOREIGN-KEY net, not its name net: a real FK
        // to `partners` under a column no naming convention would guess.
        'party_contacts.party_id' => 'cascadeOnDelete; the partner s own contact people, removed with it',

        // --- Fiscal journal ---------------------------------------------
        // The DECISION to exclude stands, but NOT on the reasoning this lane
        // first shipped. "It can never be the sole reference because the
        // underlying transaction is already counted" was REFUTED by the R2-S
        // authz gate: ACCOUNT_CHARGE, ACCOUNT_PAYMENT and DEPOSIT_RECEIPT
        // write NO `pos_receipts` and NO `documents` row by default, and
        // their Treasury counterparts are contingent on the Treasury module
        // being active AND its bridge having run. For those three event
        // types `fiscal_events` really could have been the only trace.
        //
        // What makes the exclusion safe is the fix applied alongside it:
        // each of those three event types has a SEALED projection —
        // `pos_account_charge_receipts`, `pos_account_payment_receipts`,
        // `pos_deposit_receipts` — and all three are now counted by
        // PosPartnerReferenceSource. The partner is therefore blocked by the
        // projection of the event rather than by the raw event stream, which
        // is the better key to surface: an operator can act on a deposit
        // receipt, but `fiscal_events` is append-only and immutable, so a
        // count there names a blocker nobody can ever clear.
        'fiscal_events.partner_id' => 'append-only immutable event stream; the three event types that leave no other trace (ACCOUNT_CHARGE, ACCOUNT_PAYMENT, DEPOSIT_RECEIPT) are covered by their sealed pos_*_receipts projections, which ARE counted',

        // --- Audit log of an action ABOUT the partner --------------------
        'customer_history_searches.partner_id' => 'append-only PII audit of a cashier SEARCHING for a customer; rows are never deletable, so counting them would block any partner ever looked up',

        // --- Transient preference ----------------------------------------
        'catalog_cart_items.preferred_supplier_partner_id' => 'transient per-cart supplier preference (SET NULL); an abandoned cart must not veto a supplier s deletion',

        // --- Partner-shaped name, NOT a partner reference -----------------
        'tenant_subscriptions.stripe_customer_id' => 'a Stripe customer identifier on a central-DB billing table; nothing to do with partners',
    ];

    /**
     * Tables whose declarations are allowed to set `hasSoftDeletes: true`,
     * i.e. to stop counting rows once they are soft-deleted.
     *
     * Curated on purpose (R2-S treasury m-1). Pinning the flag to "does the
     * table have a `deleted_at` column" alone is a trap: the day a MONEY
     * table gains soft deletes, that rule would invite flipping the flag to
     * keep the schema test green — and silently weaken the guard, because
     * soft-deleted money rows would stop blocking. With this allowlist the
     * flip does not compile past the test without a deliberate edit here.
     *
     * @var array<string, string> table => why ignoring its soft-deleted rows is safe
     */
    public const array SOFT_DELETE_AWARE_TABLES = [
        'documents' => 'a soft-deleted document is already withdrawn from the ledger and from every list',
        'vouchers' => 'a soft-deleted voucher is void; it is no longer a redeemable liability',
        'workshop_work_orders' => 'a soft-deleted work order is cancelled history',
        'workshop_work_order_lines' => 'lines follow their work order',
        'scheduling_appointments' => 'a soft-deleted appointment is a cancelled booking',
        'vehicles' => 'a soft-deleted vehicle is off the fleet and unreachable in the UI',
        'loyalty_members' => 'a soft-deleted member is deactivated; its points balance is no longer redeemable',
    ];

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
