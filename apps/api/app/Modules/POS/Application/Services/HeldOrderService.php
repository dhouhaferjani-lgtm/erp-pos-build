<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\HeldOrder;
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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If order not found
     */
    public function recallOrder(string $heldOrderId): HeldOrder
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var HeldOrder $heldOrder */
        $heldOrder = HeldOrder::where('company_id', $companyId)
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
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If order not found
     */
    public function discardOrder(string $heldOrderId): void
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var HeldOrder $heldOrder */
        $heldOrder = HeldOrder::where('company_id', $companyId)
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
        $companyId = $this->companyContext->requireCompanyId();

        $query = HeldOrder::where('company_id', $companyId)
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
     * Expire all held orders that have passed their expiry time.
     *
     * Finds orders where expires_at < now and status = held,
     * then sets their status to expired.
     *
     * @return int Number of orders expired
     */
    public function expireOrders(): int
    {
        return HeldOrder::where('status', HeldOrderStatus::Held)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => HeldOrderStatus::Expired]);
    }
}
