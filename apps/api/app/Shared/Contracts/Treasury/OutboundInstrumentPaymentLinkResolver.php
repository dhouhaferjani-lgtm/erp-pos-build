<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentPaymentLink;

/**
 * Read port that resolves an OUTBOUND payment instrument's settlement linkage
 * (`repository_id` + `payment_method_id`) for a given tenant/company, without
 * exposing the Treasury `PaymentInstrument` model across the module boundary.
 *
 * Task 3 (treasury burn-down). Consumed by the Expense-module listener
 * `SyncExpenseOnInstrumentLifecycle` via constructor injection; the concrete
 * `EloquentOutboundInstrumentPaymentLinkResolver` lives in
 * `App\Modules\Treasury\Infrastructure\` and is bound in `TreasuryServiceProvider`.
 * Mirrors the `App\Shared\Contracts\Fiscal\PaymentMethodResolver` seam pattern.
 */
interface OutboundInstrumentPaymentLinkResolver
{
    /**
     * Resolve the outbound settlement linkage for an instrument.
     *
     * Returns `null` when no OUTBOUND instrument with the given id exists in the
     * supplied tenant/company scope — this covers both "instrument absent" and
     * "instrument exists but is not outbound". The caller (the Expense listener)
     * treats `null` as a fail-loud inconsistency for a cleared expense
     * instrument. The lookup MUST be scoped to `$tenantId` and `$companyId`;
     * a cross-tenant or cross-company id MUST resolve to `null`.
     *
     * @param  string  $instrumentId  Treasury `payment_instruments.id` (UUID).
     * @param  string  $tenantId  Tenant UUID — the lookup MUST be tenant-scoped.
     * @param  string  $companyId  Company UUID — the lookup MUST be company-scoped.
     */
    public function resolveOutboundLink(
        string $instrumentId,
        string $tenantId,
        string $companyId,
    ): ?OutboundInstrumentPaymentLink;
}
