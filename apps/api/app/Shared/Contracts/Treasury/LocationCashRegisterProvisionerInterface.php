<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

/**
 * A POS-enabled location owns its own cash drawer — campaign lane N-12.
 *
 * Before this lane a company had one set of repositories with `location_id =
 * NULL` and every branch's POS cash resolved to whichever cash register sorted
 * first (in practice the MAIN location's till). Two branches' takings
 * commingled in one balance, so a per-branch cash count could not reconcile
 * against anything. The Treasury tender resolver
 * now resolves per location; this contract is the other half — making sure the
 * drawer a POS-enabled location needs actually exists.
 *
 * Lives in `Shared/Contracts` because the callers are outside Treasury: the
 * Company module provisions on location create / `pos_enabled` flip, and the
 * POS module asks the read question before letting a device claim a terminal
 * there. Module boundaries are sacred (CLAUDE.md rule 6) — neither may reach
 * into Treasury's models.
 */
interface LocationCashRegisterProvisionerInterface
{
    /**
     * Does this location have a GL-linked cash register of its own?
     *
     * False does NOT mean "no cash can be taken here": a tenant provisioned
     * before N-12 carries `location_id = NULL` repositories that still serve
     * every terminal (the resolver's tier 2). Use {@see hasUsableCashRegister}
     * for the "can this terminal take money?" question.
     */
    public function hasOwnCashRegister(string $tenantId, string $companyId, string $locationId): bool;

    /**
     * Can a POS receipt authored at this location resolve a cash repository at
     * all — i.e. does the resolver have a candidate for it?
     *
     * True when the location owns a GL-linked cash register, OR when the
     * company still has an unattributed (legacy) one. This is the predicate an
     * acquisition path refuses on: it is exactly "will the treasury bridge be
     * able to book this terminal's cash without borrowing another branch's
     * till?".
     */
    public function hasUsableCashRegister(string $tenantId, string $companyId, string $locationId): bool;

    /**
     * Create the location's own cash register if it has none, and return its id
     * (or the existing one's). Returns null when the drawer could not be
     * created — the company has no `cash` purpose account in its chart, so a
     * repository would be born un-GL-linked and therefore invisible to the
     * resolver anyway.
     *
     * Idempotent: calling it twice never mints a second drawer.
     *
     * `$locationCode` only seeds the repository code (`locations.code` is
     * nullable, and a caller may not have one) — the implementation falls back
     * to a generic stem and, either way, resolves a code that satisfies
     * `payment_repositories`' UNIQUE(company_id, code).
     */
    public function provision(string $tenantId, string $companyId, string $locationId, ?string $locationCode): ?string;
}
