<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Domain\Enums\DepositReferenceRefusal;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonImmutable;

/**
 * Pre-flight resolvability check for a back-office deposit's Treasury references.
 *
 * W-5c D1: `RecordCustomerDepositService` used to author and SEAL the
 * `DEPOSIT_RECEIPT` fiscal event before anything resolved the payment method /
 * repository it names — the {@see TreasuryDepositBridge} only discovered a
 * dangling reference during the (post-commit) projection run, by which point the
 * chain entry was immutable. This service lets the Partner deposit orchestrator
 * ask the SAME questions the bridge will ask, BEFORE it authors anything,
 * without touching Treasury models directly (CLAUDE.md rule 6).
 *
 * **Parity is the contract, and it is enumerated — but it is CONDITIONAL.** Each
 * case of {@see DepositReferenceRefusal} maps to one invariant enforced post-seal
 * today. The `Applies` column is load-bearing: the last four invariants live in
 * `TreasuryMovementService::record()`, and the bridge SKIPS that call entirely
 * for a maturity tender (`shouldRecordMovement = false` at :169-174, early return
 * at :217-219), so those refusals must not fire on the maturity path.
 *
 * | Refusal | Applies | Post-seal counterpart |
 * |---|---|---|
 * | `PaymentMethodNotFound` | always | `TreasuryDepositBridge::resolvePaymentMethod()` — tenant, company, code, `is_active` |
 * | `RepositoryNotFound` | always | `TreasuryDepositBridge::resolveRepository()` — tenant, company, key, `is_active` |
 * | `RepositoryMissingGlAccount` | always | `resolveRepository()` — `gl_account_id ?? account_id` non-null |
 * | `RepositoryGlAccountInactive` | always | `resolveRepository()` — that Account exists, in-company, `is_active` |
 * | `ActorNotActiveCompanyMember` | always | `TreasuryDepositBridge::resolveActorUserId()` — ACTIVE-membership arm only, see below |
 * | `RepositoryCurrencyMismatch` | movement path only | `TreasuryMovementService::record()` currency guard (:82-84) |
 * | `RepositoryFrozen` | movement path only | `record()` freeze policy (:91-96) with `allowWhileFrozen: false` for a server-only DEPOSIT_RECEIPT |
 * | `RepositoryBehindCheckpoint` | movement path only | `record()` checkpoint policy — `checkpointDisposition()` (:852-874) with `allowBehindCheckpoint: false` (bridge :273 is constant FALSE here) |
 *
 * **Deliberate omission, recorded for parity (fiscal gate m-2 / authz m-1).**
 * `resolveActorUserId()` has TWO arms: the actor row must exist in the tenant,
 * and it must hold an Active membership. Only the second is mirrored. The first
 * is unreachable in practice — `fiscal_events.actor_user_id` and
 * `user_company_memberships.user_id` both FK to `users`, so a membership row
 * proves the user row, and a hard delete cascades both away together. Mirroring
 * it would add a query that can never change the answer.
 *
 * **HONEST SCOPE — the three TOCTOU rows NARROW a race, they do not close it
 * (R2-K-prev, ticket §48-63).** A freeze, a membership revocation and a
 * reconciliation checkpoint are time-of-check/time-of-use vectors: the state they
 * read can change after this method returns.
 * `RecordCustomerDepositService` re-runs this check INSIDE the transaction that
 * seals the event, which narrows the window from "the whole request" to "the
 * width of the sealing transaction", but the projection runs AFTER that
 * transaction commits, so an orphan is still reachable. Detection and
 * disposition of orphans that slip through is the separate recoverability lane
 * (R2-K-rec) — deliberately NOT attempted here.
 *
 * Ticket: `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md`.
 *
 * **Cross-module import debt (recorded, not fixed).** This service imports
 * `Accounting\Domain\Account` and `Company\Domain\UserCompanyMembership`
 * directly, which CLAUDE.md rule 6 forbids. Both mirror predicates
 * {@see TreasuryDepositBridge} already carries with the identical imports, and
 * DIVERGING from the bridge is the exact failure mode this service exists to
 * prevent. The clean shape is a small read port in `Shared/Contracts/`
 * (`activeAccountExists(...)`, `hasActiveCompanyMembership(...)`) that BOTH the
 * bridge and this service depend on — the membership predicate now has THREE
 * copies across the codebase; see the ticket's cross-module debt section for the
 * full site list. Fix them together or not at all.
 *
 * The maturity predicate is the counter-example done right: rather than
 * re-deriving `has_maturity && instrument_kind ∈ {Cheque, Effet}`, this service
 * injects the very same {@see HandlesMaturityTenderLeg} collaborator the bridge
 * consults, so that one cannot drift at all.
 */
final class DepositReferenceResolutionService
{
    public function __construct(
        private readonly HandlesMaturityTenderLeg $maturityLegHandler,
    ) {}

    /**
     * The first invariant the named references violate, or null when the deposit
     * is projectable.
     *
     * Evaluation order mirrors the order the bridge discovers the failures in
     * (`resolvePaymentMethod` -> `resolveRepository` -> `resolveActorUserId` ->
     * the movement port's currency guard -> its freeze policy -> its checkpoint
     * policy), so the refusal a caller sees is the one they would have hit
     * post-seal.
     *
     * @param  string  $actorUserId  the user who will be stamped on the event as
     *                               the recording actor
     */
    public function refusalFor(
        string $tenantId,
        string $companyId,
        string $methodCode,
        ?string $repositoryId,
        string $currencyCode,
        string $actorUserId,
    ): ?DepositReferenceRefusal {
        // Loaded (not `exists()`) because the maturity predicate below needs the
        // row: `has_maturity` + `instrument_kind` decide whether the movement
        // port runs at all, and therefore whether its three guards apply.
        $paymentMethod = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $methodCode)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod instanceof PaymentMethod) {
            return DepositReferenceRefusal::PaymentMethodNotFound;
        }

        if ($repositoryId === null || $repositoryId === '') {
            return DepositReferenceRefusal::RepositoryNotFound;
        }

        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKey($repositoryId)
            ->first();

        if (! $repository instanceof PaymentRepository) {
            return DepositReferenceRefusal::RepositoryNotFound;
        }

        // Canonicalized on `gl_account_id` with a fallback to the legacy
        // `account_id`, exactly as `resolveRepository()` does for repositories
        // seeded before the gl_account_id column landed.
        $accountId = $repository->gl_account_id ?? $repository->account_id;
        if ($accountId === null) {
            return DepositReferenceRefusal::RepositoryMissingGlAccount;
        }

        $accountUsable = Account::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKey($accountId)
            ->exists();

        if (! $accountUsable) {
            return DepositReferenceRefusal::RepositoryGlAccountInactive;
        }

        // R2-K-prev V2 — mirrors the ACTIVE-MEMBERSHIP ARM of
        // `TreasuryDepositBridge::resolveActorUserId()` (its actor-row-exists arm
        // is deliberately not mirrored; see the class docblock for the
        // FK-cascade justification). The bridge requires an ACTIVE membership,
        // not mere existence, because `PaymentAllocationService` only posts the
        // customer-advance journal entry when the actor resolves to a User; a
        // non-member actor therefore fails loud POST-seal today. TOCTOU: see the
        // class docblock.
        $actorIsActiveMember = UserCompanyMembership::query()
            ->where('user_id', $actorUserId)
            ->where('company_id', $companyId)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if (! $actorIsActiveMember) {
            return DepositReferenceRefusal::ActorNotActiveCompanyMember;
        }

        // ---------------------------------------------------------------------
        // MOVEMENT-PORT-DERIVED REFUSALS — conditional, and that conditionality
        // is load-bearing (R2-K-prev gate, found by both reviewers).
        //
        // `TreasuryDepositBridge::apply()` takes the maturity branch at :169-174
        // for a cheque/effet tender, sets `shouldRecordMovement = false`, and
        // RETURNS at :217-219 BEFORE `TreasuryMovementService::record()` is ever
        // called. None of the port's three rejecting guards — currency, freeze,
        // checkpoint — executes on that path. Applying them anyway would refuse a
        // legitimate ops flow that projects perfectly well today (a customer
        // cheque handed over while the drawer is frozen for a cash count: no cash
        // moves, so the freeze is irrelevant).
        //
        // The predicate is not re-derived here — `HandlesMaturityTenderLeg` is
        // the same collaborator the bridge consults, so the two cannot drift.
        // ---------------------------------------------------------------------
        if ($this->maturityLegHandler->handles($paymentMethod)) {
            return null;
        }

        if ($repository->currency !== $currencyCode) {
            return DepositReferenceRefusal::RepositoryCurrencyMismatch;
        }

        // R2-K-prev V1 — mirrors the movement port's freeze policy. A server-only
        // DEPOSIT_RECEIPT is passed `allowWhileFrozen: false`, so a frozen drawer
        // makes `TreasuryMovementService::record()` throw POST-seal. TOCTOU: see
        // the class docblock. Ordered to match the port itself (currency guard
        // first, freeze policy second, checkpoint policy third).
        if ($repository->frozen_at !== null) {
            return DepositReferenceRefusal::RepositoryFrozen;
        }

        return $this->checkpointRefusal($repository);
    }

    /**
     * Mirror of `TreasuryMovementService::checkpointDisposition()` (:852-874)
     * evaluated with `allowBehindCheckpoint = false` — which is what
     * `TreasuryDepositBridge.php:273` passes for a DEPOSIT_RECEIPT, since
     * `! $event->event_type->isServerOnly()` is constant FALSE there.
     *
     * The port refuses any movement whose occurrence date is NOT strictly after
     * the repository's reconciliation checkpoint, comparing calendar dates in the
     * COMPANY timezone (not UTC) — reproduced exactly, including the timezone
     * lookup, because an off-by-one-day divergence from the port is the failure
     * mode this whole service exists to prevent.
     *
     * Occurrence is `now()`: the bridge passes `occurredAt: null` and the port
     * defaults it to `CarbonImmutable::now()`. TOCTOU like the other two — the
     * checkpoint can advance between here and the projection.
     */
    private function checkpointRefusal(PaymentRepository $repository): ?DepositReferenceRefusal
    {
        $checkpoint = $repository->last_reconciled_at;
        if ($checkpoint === null) {
            return null;
        }

        $timezone = (string) $repository->company()->value('timezone');
        $occurrenceDate = CarbonImmutable::now()->setTimezone($timezone)->toDateString();
        $checkpointDate = $checkpoint->toImmutable()->setTimezone($timezone)->toDateString();

        if ($occurrenceDate > $checkpointDate) {
            return null;
        }

        return DepositReferenceRefusal::RepositoryBehindCheckpoint;
    }
}
