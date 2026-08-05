<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;

/**
 * Pre-flight resolvability check for a back-office deposit's Treasury references.
 *
 * W-5c D1: `RecordCustomerDepositService` used to author and SEAL the
 * `DEPOSIT_RECEIPT` fiscal event before anything resolved the payment method /
 * repository it names — the {@see TreasuryDepositBridge}
 * only discovered a dangling reference during the (post-commit) projection run,
 * by which point the chain entry was immutable. This service lets the Partner
 * deposit orchestrator ask the SAME question the bridge will ask, BEFORE it
 * authors anything, without touching Treasury models directly (CLAUDE.md rule 6).
 *
 * The predicates mirror `TreasuryDepositBridge::resolvePaymentMethod()` and
 * `resolveRepository()` exactly — including `is_active = true`, which is what
 * makes a method/repository deactivated while a back-office user has the deposit
 * dialog open a 422 instead of a sealed orphan.
 */
final class DepositReferenceResolutionService
{
    public function activePaymentMethodExists(string $tenantId, string $companyId, string $methodCode): bool
    {
        return PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $methodCode)
            ->where('is_active', true)
            ->exists();
    }

    public function activeRepositoryExists(string $tenantId, string $companyId, ?string $repositoryId): bool
    {
        if ($repositoryId === null || $repositoryId === '') {
            return false;
        }

        return PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereKey($repositoryId)
            ->exists();
    }
}
