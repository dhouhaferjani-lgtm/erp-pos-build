<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Domain\Enums\DepositReferenceRefusal;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;

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
 * **Parity is the contract, and it is enumerated.** Each case of
 * {@see DepositReferenceRefusal} maps to one invariant enforced post-seal today:
 *
 * | Refusal | Post-seal counterpart |
 * |---|---|
 * | `PaymentMethodNotFound` | `TreasuryDepositBridge::resolvePaymentMethod()` — tenant, company, code, `is_active` |
 * | `RepositoryNotFound` | `TreasuryDepositBridge::resolveRepository()` — tenant, company, key, `is_active` |
 * | `RepositoryMissingGlAccount` | `resolveRepository()` — `gl_account_id ?? account_id` non-null |
 * | `RepositoryGlAccountInactive` | `resolveRepository()` — that Account exists, in-company, `is_active` |
 * | `RepositoryCurrencyMismatch` | `TreasuryMovementService::record()` currency guard |
 * | `ActorNotActiveCompanyMember` | `TreasuryDepositBridge::resolveActorUserId()` — actor is an `Active` member of the event's company |
 * | `RepositoryFrozen` | `TreasuryMovementService::record()` freeze policy (`allowWhileFrozen: false` for a server-only DEPOSIT_RECEIPT) |
 *
 * **HONEST SCOPE — the last two rows NARROW a race, they do not close it
 * (R2-K-prev, ticket §48-63).** Unlike the first five, a freeze and a membership
 * revocation are time-of-check/time-of-use vectors: the state they read can
 * change after this method returns. `RecordCustomerDepositService` re-runs this
 * check INSIDE the transaction that seals the event, which narrows the window
 * from "the whole request" to "the width of the sealing transaction", but the
 * projection runs AFTER that transaction commits, so an orphan is still
 * reachable. Detection and disposition of orphans that slip through is the
 * separate recoverability lane (R2-K-rec) — deliberately NOT attempted here.
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
 * bridge and this service depend on. Fix them together or not at all.
 */
final class DepositReferenceResolutionService
{
    /**
     * The first invariant the named references violate, or null when the deposit
     * is projectable.
     *
     * Evaluation order mirrors the order the bridge discovers the failures in
     * (`resolvePaymentMethod` -> `resolveRepository` -> `resolveActorUserId` ->
     * the movement port's currency guard -> its freeze policy), so the refusal a
     * caller sees is the one they would have hit post-seal.
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
        $methodExists = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $methodCode)
            ->where('is_active', true)
            ->exists();

        if (! $methodExists) {
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

        // R2-K-prev V2 — mirrors `TreasuryDepositBridge::resolveActorUserId()`.
        // The bridge requires an ACTIVE membership (not mere existence) because
        // `PaymentAllocationService` only posts the customer-advance journal
        // entry when the actor resolves to a User; a non-member actor therefore
        // fails loud POST-seal today. TOCTOU: see the class docblock.
        $actorIsActiveMember = UserCompanyMembership::query()
            ->where('user_id', $actorUserId)
            ->where('company_id', $companyId)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if (! $actorIsActiveMember) {
            return DepositReferenceRefusal::ActorNotActiveCompanyMember;
        }

        if ($repository->currency !== $currencyCode) {
            return DepositReferenceRefusal::RepositoryCurrencyMismatch;
        }

        // R2-K-prev V1 — mirrors the movement port's freeze policy. A server-only
        // DEPOSIT_RECEIPT is passed `allowWhileFrozen: false`, so a frozen drawer
        // makes `TreasuryMovementService::record()` throw POST-seal. TOCTOU: see
        // the class docblock. Checked LAST, matching the port's own ordering
        // (currency guard first, freeze policy second).
        if ($repository->frozen_at !== null) {
            return DepositReferenceRefusal::RepositoryFrozen;
        }

        return null;
    }
}
