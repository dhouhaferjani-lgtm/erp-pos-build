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
 * ### A DRAWER is what the tier is about — not every repository
 *
 * "Tier 1 is armed" therefore means *this location owns an active till or
 * safe*, and the whole tier applies to **drawers only**. A `bank_account` /
 * `virtual` repository is the company's settlement instrument, not a branch
 * thing: the seeder never mints one, the repositories UI leaves `location_id`
 * null on the ones an operator adds, and every branch's card takings settle
 * into the same account. Gate r1 finding 4 measured what tiering them costs —
 * a CARD method's mapped bank account was dropped at every branch that owned a
 * till and the leg fell through to that till, i.e. card money in the cash
 * drawer. Company-wide instruments are therefore candidates from every
 * location, whatever `location_id` an operator happens to have set on them,
 * and a tender the tenant has declared NOT cash never falls back to a drawer
 * while any settlement repository exists. The mirror holds too (gate r2
 * finding 3): a tender the tenant has declared cash resolves ONLY to a drawer,
 * never to a bank or a wallet — otherwise widening the tier would have let a
 * cash receipt at a drawer-less branch book against the bank GL instead of
 * refusing.
 *
 * A `null` `$locationId` (server-authored flows with no terminal, and every
 * pre-existing caller) keeps the historical company-wide rule verbatim.
 */
final readonly class TenderRepositoryResolver
{
    /**
     * The repository types that BELONG to a location — the physical drawers a
     * branch counts at close. Everything else (`bank_account`, `virtual`) is a
     * company-wide instrument unless an operator has bound it to a location.
     *
     * @var list<string>
     */
    private const DRAWER_TYPES = [
        RepositoryType::CashRegister->value,
        RepositoryType::Safe->value,
    ];

    public function resolve(
        string $tenantId,
        string $companyId,
        ?PaymentMethod $method,
        ?string $locationId = null,
    ): ?PaymentRepository {
        try {
            // N-12 — LOCATION IS A DIMENSION OF DRAWERS. A location that owns a
            // drawer of its own is served by that drawer and never by the legacy
            // unattributed one; a location that owns none keeps tier 2. Neither
            // rule touches the company-wide instruments (see `scoped()`).
            $locationOwnsDrawer = $locationId !== null
                && $this->locationOwnsDrawer($tenantId, $companyId, $locationId);

            // Gate r1 finding 4 — a tender the tenant has declared NOT cash
            // (`payment_methods.is_cash_tender`) must not come to rest in a
            // physical drawer while the company owns anywhere else to put it.
            // Before this, a CARD leg whose mapped bank account was for any
            // reason unusable fell through the type-preferred fallback straight
            // into the till: card money counted as cash at close, an
            // unreconcilable drawer, and no trace of why.
            //
            // WHY A PREFERENCE AND NOT A REFUSAL — the gate asked for "never
            // falls back to a drawer", and a hard refusal is not shippable: a
            // freshly registered tenant owns CASH-01 and SAFE-01 and NOTHING
            // else (`PaymentRepositorySeeder::defaultRepositories()`), so every
            // card sale on day one would dead-letter. That is pinned, against
            // the real registration path, by
            // `CleanRegistrationDownstreamAssumptionsTest::
            // test_tender_repository_resolver_resolves_from_a_seeded_payment_method`,
            // which takes the alphabetically first seeded method (a NON-cash
            // one) and requires it to resolve to `CASH-01`. Ordering delivers
            // the whole of the gate's intent — while the company has any usable
            // settlement repository, a non-cash tender can never reach a drawer
            // — without turning day one into an outage.
            $nonCashTender = $method !== null && $method->is_cash_tender !== true;

            // Gate r2 finding 3 — THE MIRROR OF THE ABOVE, and the one the
            // location tier made urgent. Widening the tier so that every
            // `bank_account` / `virtual` row is a candidate from every location
            // also made one reachable for a CASH tender at a location with no
            // drawer: physical cash booked against the bank GL, with no drawer
            // movement and nothing to reconcile, instead of the loud refusal
            // this lane exists to produce.
            //
            // `PostShiftCashVarianceAdjustment::resolveSingleRepository()` has
            // carried exactly this assertion since gate finding I7
            // (`resolved_repository_is_not_a_cash_till`); the bridge had none.
            // It belongs HERE rather than at each caller, because this class's
            // whole reason for existing is that the shift-close leg and the
            // fiscal projection resolve by the same code and not by two
            // implementations that happen to agree.
            //
            // Expressed as a filter on the candidate set, not a post-hoc
            // rejection: a cash method mapped to a bank simply has no usable
            // mapping and falls through to the location's drawer, exactly as an
            // out-of-tier mapping already does. When no drawer qualifies the
            // answer is null, and every caller treats null as a refusal.
            $cashTender = $method !== null && $method->is_cash_tender === true;

            $mappedRepositoryId = $method?->default_repository_id;
            if (is_string($mappedRepositoryId)) {
                $mappedQuery = $this->scoped($tenantId, $companyId, $locationId, $locationOwnsDrawer)
                    ->where('is_active', true)
                    ->whereNotNull('gl_account_id');

                if ($cashTender) {
                    $mappedQuery->whereIn('type', self::DRAWER_TYPES);
                }

                $mapped = $mappedQuery->find($mappedRepositoryId);

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
            // Gate r1 finding 7 — `is_active` now filters here too, not only on
            // the mapped branch. Pre-N-12 the omission was benign because the
            // candidate set was company-wide; the tier makes a deactivated branch
            // till STICKY (it arms tier 1, excludes the legacy pool, and then is
            // the only candidate), so POS cash would keep booking into a drawer
            // the operator believes is switched off.
            $fallback = $this->scoped($tenantId, $companyId, $locationId, $locationOwnsDrawer)
                ->where('is_active', true)
                ->whereNotNull('gl_account_id');

            if ($cashTender) {
                $fallback->whereIn('type', self::DRAWER_TYPES);
            }

            if ($nonCashTender) {
                // Every non-drawer ahead of every drawer. A drawer is reachable
                // only when the company has no settlement repository at all.
                return $fallback
                    ->orderByRaw(
                        'CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END',
                        self::DRAWER_TYPES,
                    )
                    ->orderBy('id')
                    ->first();
            }

            return $fallback
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
     * A `null` `$locationId` is "no location known" — the historical
     * company-wide set, verbatim.
     *
     * With a location, the split is by what a repository IS, not by where it
     * sits:
     *
     *   - a DRAWER (`cash_register` / `safe`) is a physical thing that lives at
     *     one branch and is counted there. Candidates are this location's own,
     *     plus the never-attributed legacy ones and only while this location
     *     owns none. Another location's drawer is never a candidate.
     *   - anything else (`bank_account`, `virtual`) is the COMPANY's settlement
     *     instrument. Every branch's card takings settle into the same account;
     *     `location_id` on such a row is descriptive metadata an operator may
     *     have set, never a restriction. Gate r1 finding 4: tiering these
     *     alongside the drawers silently rerouted a branch's CARD leg into the
     *     branch till.
     *
     * @return Builder<PaymentRepository>
     */
    private function scoped(
        string $tenantId,
        string $companyId,
        ?string $locationId,
        bool $locationOwnsDrawer,
    ): Builder {
        $query = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        if ($locationId === null) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($locationId, $locationOwnsDrawer): void {
            $scope->whereNotIn('type', self::DRAWER_TYPES)
                ->orWhere(function (Builder $drawers) use ($locationId, $locationOwnsDrawer): void {
                    $drawers->whereIn('type', self::DRAWER_TYPES)
                        ->where(function (Builder $tier) use ($locationId, $locationOwnsDrawer): void {
                            $tier->where('location_id', $locationId);

                            if (! $locationOwnsDrawer) {
                                $tier->orWhereNull('location_id');
                            }
                        });
                });
        });
    }

    /**
     * Does this location own a till or a safe of its own?
     *
     * The question the tier turns on — see the class docblock. A location that
     * owns only, say, a bound bank account has no drawer, so the unattributed
     * legacy drawers remain its candidates exactly as before N-12.
     *
     * Gate r1 finding 7: a DEACTIVATED drawer does not arm the tier. Otherwise
     * switching a branch till off would leave the branch pinned to it — armed,
     * cut off from the legacy pool, and with that same switched-off row as the
     * only candidate.
     */
    private function locationOwnsDrawer(string $tenantId, string $companyId, string $locationId): bool
    {
        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->whereIn('type', self::DRAWER_TYPES)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->exists();
    }
}
