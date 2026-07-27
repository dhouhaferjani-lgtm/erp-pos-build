<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;

/**
 * Eloquent-backed implementation of `PaymentMethodResolver` — synthesis v5
 * §8.B + dispatch §0 Gap A.
 *
 * Walks `payment_methods` by `(tenant_id, company_id, code)`, matching the
 * schema's company-scoped uniqueness. Returns the matched row's UUID, or
 * `null` when no row exists in the requested tenant/company scope.
 *
 * **Pass 2A.PHP.2 R3 — Codex BLOCKER-3 closure (N-07).** Round-2 wrapped the
 * Eloquent call in a `try { … } catch (QueryException) { return null; }`
 * guard. That swallowed transient DB failures (timeout, deadlock, connection
 * loss) and re-presented them as "code not found in tenant" — directly
 * contradicting the interface docblock the same R2 commit added (null is
 * for the canonical "row absent" case ONLY; transient failures MUST
 * propagate so the caller's wrapping transaction rolls back and Horizon
 * retries). The catch is removed; `QueryException` propagates naturally
 * and `ApplyFiscalEventProjectionJob`'s Task 23 R2 catch-Throwable contract
 * advances the job for retry with forensic context.
 *
 * Lives in `Treasury\Infrastructure` and is bound via `TreasuryServiceProvider`
 * so the POS projector can resolve via the `PaymentMethodResolver` interface
 * (Shared/Contracts) and never imports `App\Modules\Treasury\` directly —
 * SoT §13.6/D16 bounded-modules asymmetric seam.
 */
final class EloquentPaymentMethodResolver implements PaymentMethodResolver
{
    public function resolveByCode(string $tenantId, string $companyId, string $methodCode): ?string
    {
        $method = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('code', $methodCode)
            ->first();

        if ($method === null) {
            return null;
        }

        return (string) $method->id;
    }
}
