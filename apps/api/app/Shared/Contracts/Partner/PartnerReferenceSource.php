<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Partner;

/**
 * A module's own answer to "do I still reference this partner?".
 *
 * WHY THIS CONTRACT EXISTS. `PartnerReferenceCounter` used to read three
 * table names (`documents`, `payments`, `pos_receipts`) directly with the
 * query builder. That kept the Partner module free of a compile-time
 * dependency on Document/Treasury/POS, but it bought that with an
 * UNGUARDED SCHEMA dependency: rename a column in the owning module and
 * the delete guard silently stops guarding, with nothing in the owning
 * module's test suite to notice. The L6 merge gate ruled that widening the
 * sweep past those three tables requires each owning module to answer for
 * its own tables through a `Shared/Contracts` reader
 * (`docs/superpowers/tickets/2026-08-06-l6-partners-followups.md` T1).
 *
 * Implementations are collected by the container tag
 * `PartnerReferenceSource::class` and consumed by
 * `App\Modules\Partner\Application\Services\PartnerReferenceCounter`.
 * Tag in the owning module's `ServiceProvider::register()` — the counter
 * materialises the tagged set lazily at resolve time, so registration
 * order between providers does not matter, but tagging from `boot()` is
 * still avoided to match the `FiscalEventProjector` precedent.
 *
 * CONNECTION TIMING (BUG-007, non-negotiable). Implementations MUST NOT
 * constructor-inject `Illuminate\Database\ConnectionInterface`. Laravel
 * builds controllers — and transitively every dependency of this graph —
 * during `Route::gatherMiddleware()`, which runs BEFORE `ResolveTenancy`
 * swaps `database.default` from `central` to the tenant DB. A
 * constructor-captured connection is therefore pinned to `central`, which
 * holds none of these tables. Inject `Illuminate\Database\DatabaseManager`
 * and call `->connection()` INSIDE each query instead — or simply extend
 * `App\Shared\Application\Partner\TableBackedPartnerReferenceSource`, which does this
 * for you. Guarded by `PartnerReferenceCounterConnectionTimingTest`.
 */
interface PartnerReferenceSource
{
    /**
     * Rows in this module's tables that still point at the given partner.
     *
     * Keys are table names (they become the `error.details` keys of the
     * 409 the delete endpoint returns), values are row counts. Zero counts
     * may be returned — the counter strips them.
     *
     * @return array<string, int>
     */
    public function countPartnerReferences(string $partnerId): array;
}
