<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Fiscal;

/**
 * Resolves a `payment_methods.id` from a canonical SALE_RECEIPT
 * `payments[].method_code` for a given tenant.
 *
 * Pass 2A.PHP.2 — synthesis v5 §8.B + dispatch §0 Gap A. The 27-key canonical
 * payload no longer carries `payment_method_id` per-payment; the projector
 * must resolve the tenant-scoped FK from `(tenant_id, method_code)`. This
 * contract lives in Shared/Contracts/Fiscal so the POS module
 * (`PosCoreReceiptProjection`) depends on the interface, NOT on the Treasury
 * module directly — honors SoT §13.6/D16 (bounded-modules asymmetric seam).
 *
 * The concrete `EloquentPaymentMethodResolver` lives in
 * `App\Modules\Treasury\Infrastructure\` and is bound via
 * `TreasuryServiceProvider`. The POS-core projection receives this contract
 * via constructor injection.
 *
 * Security stance: the resolver MUST scope the lookup to the supplied
 * `$tenantId`. A cross-tenant `method_code` collision MUST return `null`;
 * the caller fails closed (rolls back the projection transaction) when the
 * lookup returns `null`. This preserves the Task 21 R2 Opus F3 cross-tenant
 * FK gate without coupling the projector to Treasury directly.
 */
interface PaymentMethodResolver
{
    /**
     * Resolve a `payment_methods.id` (UUID string) from a canonical
     * `method_code` for the given tenant.
     *
     * Returns `null` when no matching row exists in the tenant scope —
     * callers MUST treat null as fail-closed (do NOT silently coerce to a
     * default method).
     *
     * @param  string  $tenantId  Tenant UUID. The resolver MUST scope the
     *                            lookup to this tenant — never a cross-tenant
     *                            match.
     * @param  string  $methodCode  Canonical method code from
     *                              `fiscal_events.payload.payments[].method_code`.
     * @return string|null UUID of the matched `payment_methods` row, or
     *                     null if no row exists in the tenant scope.
     */
    public function resolveByCode(string $tenantId, string $methodCode): ?string;
}
