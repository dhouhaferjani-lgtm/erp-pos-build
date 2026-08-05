<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
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
 *
 * Two bridge invariants are deliberately NOT covered because they are
 * time-of-check/time-of-use races rather than input validation — a repository
 * frozen between check and projection, and an actor whose company membership is
 * revoked in the same window. Both are ticketed in
 * `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md`.
 */
final class DepositReferenceResolutionService
{
    /**
     * The first invariant the named references violate, or null when the deposit
     * is projectable.
     */
    public function refusalFor(
        string $tenantId,
        string $companyId,
        string $methodCode,
        ?string $repositoryId,
        string $currencyCode,
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

        if ($repository->currency !== $currencyCode) {
            return DepositReferenceRefusal::RepositoryCurrencyMismatch;
        }

        return null;
    }
}
