<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Shared\Contracts\Treasury\DTOs\OutboundInstrumentPaymentLink;
use App\Shared\Contracts\Treasury\OutboundInstrumentPaymentLinkResolver;

/**
 * Eloquent-backed implementation of `OutboundInstrumentPaymentLinkResolver`
 * (Task 3 — treasury burn-down).
 *
 * Scopes the `payment_instruments` lookup to `(tenant_id, company_id, id)` and
 * returns the outbound settlement linkage, or `null` when the row is absent or
 * not outbound. Keeps the Treasury `PaymentInstrument` model inside the Treasury
 * module so the Expense listener depends only on the Shared contract.
 */
final class EloquentOutboundInstrumentPaymentLinkResolver implements OutboundInstrumentPaymentLinkResolver
{
    public function resolveOutboundLink(
        string $instrumentId,
        string $tenantId,
        string $companyId,
    ): ?OutboundInstrumentPaymentLink {
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($instrumentId)
            ->first();

        if (! $instrument instanceof PaymentInstrument
            || $instrument->direction !== InstrumentDirection::Outbound) {
            return null;
        }

        return new OutboundInstrumentPaymentLink(
            repositoryId: $instrument->repository_id,
            paymentMethodId: $instrument->payment_method_id,
        );
    }
}
