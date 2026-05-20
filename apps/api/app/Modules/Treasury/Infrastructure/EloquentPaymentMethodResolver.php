<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use Illuminate\Database\QueryException;

/**
 * Eloquent-backed implementation of `PaymentMethodResolver` — synthesis v5
 * §8.B + dispatch §0 Gap A.
 *
 * Walks `payment_methods` by `(tenant_id, code)` — the schema's unique
 * constraint per `2025_11_30_120000_create_treasury_tables.php:45`. Returns
 * the matched row's UUID, or `null` when no row exists in the tenant scope.
 *
 * Wrapped in a try/catch so a malformed input (e.g. an empty/oversized
 * `method_code`) cannot crash the projector — null fall-through is the
 * fail-closed signal the caller uses.
 *
 * Lives in `Treasury\Infrastructure` and is bound via `TreasuryServiceProvider`
 * so the POS projector can resolve via the `PaymentMethodResolver` interface
 * (Shared/Contracts) and never imports `App\Modules\Treasury\` directly —
 * SoT §13.6/D16 bounded-modules asymmetric seam.
 */
final class EloquentPaymentMethodResolver implements PaymentMethodResolver
{
    public function resolveByCode(string $tenantId, string $methodCode): ?string
    {
        try {
            $method = PaymentMethod::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $methodCode)
                ->first();
        } catch (QueryException) {
            return null;
        }

        if ($method === null) {
            return null;
        }

        return (string) $method->id;
    }
}
