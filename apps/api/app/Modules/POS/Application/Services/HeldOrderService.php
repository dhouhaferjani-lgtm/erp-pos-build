<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\HeldOrder;
use App\Shared\Domain\CurrencyScale;
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

        // Canonicalise numeric snapshot values to fixed precision before they
        // are frozen into the JSONB column. Money fields resolve their scale
        // from the company currency (context-safe: this runs in the request
        // context where the company is always resolvable). bcmath only — no
        // float conversion — so we never reintroduce IEEE-754 drift.
        $moneyScale = CurrencyScale::for($company->currency);
        $cartSnapshot = $this->canonicaliseSnapshotScales($cartSnapshot, $moneyScale);

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
     * Canonicalise the numeric fields inside a cart snapshot to fixed scales.
     *
     * Quantity → 4 dp, tax_rate → 2 dp, money (unit_price, discount_amount) →
     * the resolved currency scale. Only present, numeric values are rewritten;
     * nulls and non-numeric values are left untouched so the structure (and any
     * non-monetary metadata) survives intact.
     *
     * @param  array<string, mixed>  $cartSnapshot
     * @return array<string, mixed>
     */
    private function canonicaliseSnapshotScales(array $cartSnapshot, int $moneyScale): array
    {
        if (! isset($cartSnapshot['lines']) || ! is_array($cartSnapshot['lines'])) {
            return $cartSnapshot;
        }

        foreach ($cartSnapshot['lines'] as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $cartSnapshot['lines'][$index]['quantity'] = $this->scaleValue($line['quantity'] ?? null, 4);
            $cartSnapshot['lines'][$index]['unit_price'] = $this->scaleValue($line['unit_price'] ?? null, $moneyScale);
            $cartSnapshot['lines'][$index]['tax_rate'] = $this->scaleValue($line['tax_rate'] ?? null, 2);
            $cartSnapshot['lines'][$index]['discount_amount'] = $this->scaleValue($line['discount_amount'] ?? null, $moneyScale);
        }

        return $cartSnapshot;
    }

    /**
     * Format a single numeric snapshot value via bcmath, preserving null and
     * leaving non-numeric values unchanged (the validator already rejects
     * over-precise / malformed numerics before we reach this point).
     */
    private function scaleValue(mixed $value, int $scale): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $value;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        return CurrencyScale::bcformat($value, $scale);
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
