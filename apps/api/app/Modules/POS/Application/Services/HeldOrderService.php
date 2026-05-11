<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\HeldOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Service for managing held (parked) POS orders.
 *
 * Held orders allow cashiers to save a cart and recall it later,
 * supporting workflows like customer stepping away, split service, etc.
 */
final class HeldOrderService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Hold the current cart as a new held order.
     *
     * @param  array<string, mixed>  $cartSnapshot  The full cart state to preserve
     * @param  int|null  $expiresInMinutes  Minutes until expiry (default 240 = 4 hours)
     *
     * @throws \InvalidArgumentException If cart snapshot is empty or has no lines
     */
    public function holdOrder(
        string $terminalId,
        string $shiftId,
        string $cashierId,
        array $cartSnapshot,
        ?string $label,
        ?int $expiresInMinutes = 240,
    ): HeldOrder {
        $lines = $cartSnapshot['lines'] ?? [];
        if (count($lines) === 0) {
            throw new \InvalidArgumentException('Cannot hold an empty cart. At least one line item is required.');
        }

        $company = $this->companyContext->requireCompany();
        $now = now();

        $expiresAt = $expiresInMinutes !== null
            ? $now->copy()->addMinutes($expiresInMinutes)
            : null;

        return HeldOrder::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'cashier_id' => $cashierId,
            'label' => $label,
            'cart_snapshot' => $cartSnapshot,
            'status' => HeldOrderStatus::Held,
            'held_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Recall a held order back to the cart.
     *
     * Sets the order status to recalled and records the recall timestamp.
     *
     * @throws \RuntimeException If the order cannot be recalled
     * @throws ModelNotFoundException If order not found
     */
    public function recallOrder(string $heldOrderId): HeldOrder
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        /** @var HeldOrder $heldOrder */
        $heldOrder = HeldOrder::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($heldOrderId);

        if (! $heldOrder->canBeRecalled()) {
            if ($heldOrder->isRecalled()) {
                throw new \RuntimeException('This order has already been recalled.');
            }
            if ($heldOrder->isExpired() || ($heldOrder->expires_at !== null && $heldOrder->expires_at->isPast())) {
                throw new \RuntimeException('This held order has expired and cannot be recalled.');
            }
            throw new \RuntimeException('This order cannot be recalled.');
        }

        $heldOrder->update([
            'status' => HeldOrderStatus::Recalled,
            'recalled_at' => now(),
        ]);

        return $heldOrder->fresh() ?? $heldOrder;
    }

    /**
     * Discard (delete) a held order permanently.
     *
     * @throws ModelNotFoundException If order not found
     */
    public function discardOrder(string $heldOrderId): void
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        /** @var HeldOrder $heldOrder */
        $heldOrder = HeldOrder::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($heldOrderId);

        $heldOrder->delete();
    }

    /**
     * List held orders for a terminal, optionally filtered by shift.
     *
     * Returns only orders in 'held' status that have not expired.
     * Ordered by held_at descending (most recent first).
     *
     * @return Collection<int, HeldOrder>
     */
    public function listHeldOrders(string $terminalId, ?string $shiftId = null): Collection
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $query = HeldOrder::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->forTerminal($terminalId)
            ->held()
            ->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('held_at');

        if ($shiftId !== null) {
            $query->forShift($shiftId);
        }

        return $query->get();
    }

    /**
     * Expire all held orders for a single (tenant, company) pair that have
     * passed their expiry time.
     *
     * Round-5 — both predicates are required by the cluster invariant. The
     * caller (`pos:expire-held-orders`) iterates per tenant + per company so
     * the UPDATE never crosses tenants.
     *
     * @return int Number of orders expired
     */
    public function expireOrders(string $tenantId, string $companyId): int
    {
        return HeldOrder::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('status', HeldOrderStatus::Held)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => HeldOrderStatus::Expired]);
    }
}
