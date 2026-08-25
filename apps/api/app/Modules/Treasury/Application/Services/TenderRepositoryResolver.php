<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Resolve a tender's operational repository — the ONE rule, shared.
 *
 * Extracted verbatim out of `TreasuryReceiptBridge::resolveRepositoryForTender()`
 * for DPA lane G3 requirement 4. No shift→repository link exists anywhere in the
 * schema (`payment_repositories` carries `location_id` only; POS shifts carry
 * nothing), so the G3 shift-close cash-variance listener must resolve the target
 * cash repository the same way the fiscal projection bridge already does — and
 * "the same way" has to mean the same CODE, not a second implementation that
 * happens to agree today. Both callers now depend on this class; the pin lives
 * in tests/Feature/Treasury/TenderRepositoryResolverTest.php.
 *
 * The rule: a mapped repository (`PaymentMethod::default_repository_id`) wins
 * only when it belongs to the same tenant+company, is active, and remains
 * GL-linked. Otherwise the fallback — the first tenant+company GL-linked
 * repository, preferring a `cash_register`, then a `safe`, then anything else,
 * and breaking ties on the stable UUID as it always has (DPA lane H-3 gate
 * ruling C-1; before that the type preference was absent and the winner was
 * merely whichever row happened to sort first). The `is_active` filter is
 * deliberately on the mapped branch ONLY, exactly as the bridge has always had
 * it; widening it here would silently change fiscal projection behaviour.
 *
 * ## Campaign lane N-12 — LOCATION IS A TIER, NOT A TIE-BREAKER
 *
 * The rule above was tenant+company only, with no location axis at all. On a
 * multi-branch tenant that meant every branch resolved to whichever GL-linked
 * cash register sorted first by UUID — in practice the one the provisioning
 * seeder inserts first, i.e. the MAIN location's till. The Playwright campaign
 * measured exactly that: `CASH-01 in 452.000 (POS01, Main) · in 200.000 (POS02,
 * Ariana)` — two branches' takings commingled in one balance, so no per-branch
 * cash count could reconcile against anything.
 *
 * When the caller knows the money's location (a POS receipt knows its
 * terminal's location; a shift's cash count knows its terminal's location), the
 * candidate set is TIERED:
 *
 *   tier 1  `location_id = $locationId`
 *   tier 2  `location_id IS NULL`, and ONLY when tier 1 is empty
 *   never   a repository attributed to a DIFFERENT location
 *
 * Tier 2 is what makes this forward-only rather than a fleet-wide behaviour
 * break: every pre-N-12 tenant was provisioned with `location_id = NULL` on
 * both seeded repositories (that is N-12's original wave-1 finding), and those
 * tenants keep resolving exactly as they did. The moment a location owns a
 * drawer, that drawer is the only answer for that location — and a location
 * with no drawer of its own resolves to NULL, which every caller treats as a
 * refusal. Borrowing another branch's till is never an outcome.
 *
 * A `null` `$locationId` (server-authored flows with no terminal, and every
 * pre-existing caller) keeps the historical company-wide rule verbatim.
 */
final readonly class TenderRepositoryResolver
{
    public function resolve(
        string $tenantId,
        string $companyId,
        ?PaymentMethod $method,
        ?string $locationId = null,
    ): ?PaymentRepository {
        try {
            // N-12: with a known location, resolution runs against tier 1
            // (that location's own repositories) and falls to tier 2 (the
            // never-attributed legacy rows) only when tier 1 is empty. The
            // MAPPED branch is inside the tier too, deliberately: a
            // company-wide `default_repository_id` is operator routing policy,
            // and letting it point a branch's cash at Main's till would
            // reinstate the exact commingling this lane removes.
            $tier = $locationId === null
                ? null
                : ($this->locationHasRepositories($tenantId, $companyId, $locationId) ? $locationId : false);

            $mappedRepositoryId = $method?->default_repository_id;
            if (is_string($mappedRepositoryId)) {
                $mapped = $this->scoped($tenantId, $companyId, $tier)
                    ->where('is_active', true)
                    ->whereNotNull('gl_account_id')
                    ->find($mappedRepositoryId);

                if ($mapped instanceof PaymentRepository) {
                    return $mapped;
                }
            }

            // DPA lane H-3 / gate ruling C-1 — deterministic TYPE preference ahead
            // of the historical UUID ordering. A tenant is provisioned with a cash
            // register and a safe (PaymentRepositorySeeder), and an unmapped cash
            // tender belongs in the till, not the safe. Before this, the winner was
            // whichever row sorted first by UUID: correct today only because
            // `HasUuids` mints time-ordered uuid7 and the seeder inserts CASH-01
            // first — an emergent property that a framework bump, a switch to
            // uuid4, or a reordered seeder array would silently invert, rerouting
            // every new tenant's POS cash to the safe.
            //
            // Tie-preserving by construction: the CASE only separates rows of
            // DIFFERENT types, so any fixture whose repositories share a type (all
            // of PaymentRepositoryFactory's, whose default is `cash_register`)
            // falls straight through to `orderBy('id')` — the previous behaviour,
            // unchanged. The `is_active` filter deliberately stays on the mapped
            // branch only; widening it here would change fiscal projection
            // behaviour (see the class docblock).
            return $this->scoped($tenantId, $companyId, $tier)
                ->whereNotNull('gl_account_id')
                ->orderByRaw(
                    'CASE WHEN type = ? THEN 0 WHEN type = ? THEN 1 ELSE 2 END',
                    [RepositoryType::CashRegister->value, RepositoryType::Safe->value],
                )
                ->orderBy('id')
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Same rule, entered from a payment-method ID rather than a hydrated model —
     * what an event-driven caller has (the cash-count breakdown carries
     * `payment_method_id`).
     *
     * Gate finding I6: a method id that is PRESENT but does not load in
     * tenant+company scope now returns null — it does NOT degrade to the
     * null-method fallback. The bridge treats that same input as fatal
     * (`TreasuryReceiptBridge`: "payment_method_id … resolved but not loadable"),
     * so falling back here would have been a real divergence between the two
     * callers, and would have silently routed an unknown tender's money to
     * whichever GL-linked repository sorts first by UUID. A caller that gets
     * null refuses to write; the bridge throws. Neither invents a destination.
     *
     * A genuinely ABSENT id (null/empty) still uses the null-method fallback,
     * which is the bridge's behaviour for a tender the canonical payload never
     * named.
     *
     * The lookup itself sits inside the same `QueryException` guard `resolve()`
     * and the bridge both carry.
     */
    public function resolveByMethodId(
        string $tenantId,
        string $companyId,
        ?string $methodId,
        ?string $locationId = null,
    ): ?PaymentRepository {
        if (! is_string($methodId) || $methodId === '') {
            return $this->resolve($tenantId, $companyId, null, $locationId);
        }

        try {
            $method = PaymentMethod::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->find($methodId);
        } catch (QueryException) {
            return null;
        }

        if (! $method instanceof PaymentMethod) {
            return null;
        }

        return $this->resolve($tenantId, $companyId, $method, $locationId);
    }

    /**
     * Tenant+company base query, narrowed to the resolved N-12 tier.
     *
     * `$tier` is a location id (tier 1), `false` (tier 2 — the never-attributed
     * rows), or `null` (no location known: the historical company-wide set).
     *
     * @return Builder<PaymentRepository>
     */
    private function scoped(string $tenantId, string $companyId, string|false|null $tier): Builder
    {
        $query = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        if (is_string($tier)) {
            return $query->where('location_id', $tier);
        }

        if ($tier === false) {
            return $query->whereNull('location_id');
        }

        return $query;
    }

    private function locationHasRepositories(string $tenantId, string $companyId, string $locationId): bool
    {
        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->whereNotNull('gl_account_id')
            ->exists();
    }


}
