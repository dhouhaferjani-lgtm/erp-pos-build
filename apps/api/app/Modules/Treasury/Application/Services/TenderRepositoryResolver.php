<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
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
 * The rule (unchanged): a mapped repository (`PaymentMethod::default_repository_id`)
 * wins only when it belongs to the same tenant+company, is active, and remains
 * GL-linked. Otherwise the historical deterministic fallback — the first
 * tenant+company GL-linked repository ordered by stable UUID. The `is_active`
 * filter is deliberately on the mapped branch ONLY, exactly as the bridge has
 * always had it; widening it here would silently change fiscal projection
 * behaviour.
 */
final readonly class TenderRepositoryResolver
{
    public function resolve(string $tenantId, string $companyId, ?PaymentMethod $method): ?PaymentRepository
    {
        try {
            $mappedRepositoryId = $method?->default_repository_id;
            if (is_string($mappedRepositoryId)) {
                $mapped = PaymentRepository::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNotNull('gl_account_id')
                    ->find($mappedRepositoryId);

                if ($mapped instanceof PaymentRepository) {
                    return $mapped;
                }
            }

            return PaymentRepository::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereNotNull('gl_account_id')
                ->orderBy('id')
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Same rule, entered from a payment-method ID rather than a hydrated model —
     * what an event-driven caller has (the cash-count breakdown carries
     * `payment_method_id`). A method id that does not resolve in scope degrades
     * to the null-method fallback, exactly as the bridge does when its own
     * method lookup misses.
     */
    public function resolveByMethodId(string $tenantId, string $companyId, ?string $methodId): ?PaymentRepository
    {
        $method = null;

        if (is_string($methodId) && $methodId !== '') {
            $method = PaymentMethod::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->find($methodId);
        }

        return $this->resolve($tenantId, $companyId, $method);
    }
}
