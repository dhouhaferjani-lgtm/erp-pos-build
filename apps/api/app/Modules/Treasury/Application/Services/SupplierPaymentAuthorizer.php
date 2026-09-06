<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * F-W2-14 residual (a) — `POST /api/v1/payments` is TWO acts behind one gate.
 *
 * The route carries `can:payments.create`, which `cashier` and `operator` hold
 * so a till can take a CUSTOMER payment. The very same endpoint settles a
 * SUPPLIER invoice: cash leaves a repository, 401 is debited, and on the wave-2
 * browser run a cashier did exactly that (`W2-PERM-6..11`, 201 with a cash
 * movement out).
 *
 * This guard splits the two by the SHAPE OF THE REQUEST — never by role — and
 * requires the dedicated `payments.pay-supplier` on the AP side only, so every
 * customer-side payment a cashier could take before still works unchanged.
 *
 * "Supplier-side" is decided in this order:
 *  1. any allocated/settled DOCUMENT whose type is a supplier settlement
 *     ({@see DocumentType::isSupplierSettlement()}) —
 *     the authoritative signal, and the one the GL branch in `PaymentController`
 *     keys on; then
 *  2. no such document, but the PARTNER is a pure `supplier` — an advance/on-account
 *     payment to a supplier is still money going out to a supplier. `PartnerType::Both`
 *     is deliberately NOT included: with no supplier document named, the controller
 *     treats the payment as a customer receipt, and denying it would break the
 *     mixed-role partner's AR flow for a cashier.
 *
 * It runs BEFORE any row is written (and before the write transaction opens), so
 * a refusal leaves no `payments` row and no repository movement.
 */
final class SupplierPaymentAuthorizer
{
    /**
     * @param  list<string>  $documentIds  every document the request names (allocations, settled document, excess allocations)
     *
     * @throws AuthorizationException 403 when the payment is supplier-side and the actor lacks `payments.pay-supplier`.
     */
    public function assertMayPay(
        User $user,
        string $tenantId,
        string $companyId,
        ?string $partnerId,
        array $documentIds,
    ): void {
        if (! $this->isSupplierSide($tenantId, $companyId, $partnerId, $documentIds)) {
            return;
        }

        Gate::forUser($user)->authorize('payments.pay-supplier');
    }

    /**
     * @param  list<string>  $documentIds
     */
    private function isSupplierSide(
        string $tenantId,
        string $companyId,
        ?string $partnerId,
        array $documentIds,
    ): bool {
        $documentIds = array_values(array_unique(array_filter(
            $documentIds,
            static fn (string $id): bool => $id !== '',
        )));

        if ($documentIds !== []) {
            $documents = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereIn('id', $documentIds)
                ->get(['id', 'type']);

            foreach ($documents as $document) {
                if ($document->type->isSupplierSettlement()) {
                    return true;
                }
            }

            // A named document that is NOT a supplier settlement makes this an
            // AR/other payment; the partner fallback below would only add false
            // positives (a `Both` partner already fails it, and a mis-typed
            // customer document on a supplier partner is refused by
            // DocumentAllocationStateGuard with a typed 422, which is the more
            // useful answer than a 403).
            return false;
        }

        if ($partnerId === null) {
            return false;
        }

        $partnerType = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($partnerId)
            ->value('type');

        return $partnerType === PartnerType::Supplier;
    }
}
