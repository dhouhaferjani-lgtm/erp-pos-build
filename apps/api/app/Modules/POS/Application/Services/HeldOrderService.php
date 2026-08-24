<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Exceptions\HeldOrderDiscardRefusedException;
use App\Modules\POS\Domain\Exceptions\HeldOrderRecallConflictException;
use App\Modules\POS\Domain\HeldOrder;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Q-8 — a parked basket is a single-consumption resource: whoever recalls
     * it gets the full `cart_snapshot` and rings it up. The pre-Q-8 shape was
     * `findOrFail` → `canBeRecalled()` → `update()` with no lock and no
     * transaction, so two tills racing on the same basket both passed the
     * guard on a stale read and the customer's cart was sold (and stock
     * decremented) twice. This is now:
     *
     *   1. a transaction wrapping read and write;
     *   2. `lockForUpdate()` on the read, which serialises the two tills on
     *      PostgreSQL before either reaches the guard;
     *   3. a conditional `UPDATE ... WHERE status = 'held'` asserting exactly
     *      one affected row, which is the only defence on SQLite (where
     *      `FOR UPDATE` is a no-op) and the belt to (2)'s braces on PG.
     *
     * WHICH REFUSAL THE LOSER GETS (fix round after gate r1 — the earlier
     * shape advertised a 409 that production could never reach):
     *
     * | observed state under the lock        | raised                              | HTTP |
     * |--------------------------------------|-------------------------------------|------|
     * | `recalled` (another actor consumed)  | `HeldOrderRecallConflictException`  | 409  |
     * | conditional UPDATE affected 0 rows   | `HeldOrderRecallConflictException`  | 409  |
     * | `expired`, or TTL lapsed while held  | `\RuntimeException`                 | 422  |
     * | soft-deleted / wrong tenant/terminal | `ModelNotFoundException`            | 404  |
     *
     * The 409/422 split is "was it consumed by another actor, or did it lapse
     * on its own timer": 409's remedy is refresh-the-list-and-pick-another,
     * 422's is that the basket is simply gone. On PostgreSQL under READ
     * COMMITTED the loser's `SELECT ... FOR UPDATE` blocks on the winner and
     * then re-reads the NEW row version (EvalPlanQual), so it observes
     * `recalled` and lands on the FIRST row of the table above — which is why
     * the `isRecalled()` branch, not the conditional UPDATE, is the production
     * conflict site. The conditional UPDATE remains the second backstop and
     * raises the same typed conflict, so the 409 contract holds on every
     * driver.
     *
     * `$expectedTerminalId` scopes the lookup the way `listHeldOrders()` has
     * always been scoped — a basket parked on till A is never listed on till
     * B, so a recall claiming till B must miss. It is OPTIONAL ON THE WIRE for
     * BACKWARD COMPATIBILITY: the live web client
     * (`apps/web/src/features/pos/api/heldOrderApi.ts:106`) POSTs bare, so
     * making the field required today would 404 every live recall.
     * Consequently the audit item "held-order recall is not terminal-scoped"
     * is NOT closed by this lane — the capability exists but nothing exercises
     * it. The follow-up (web `useRecallOrder` sends `terminal_id`, then the
     * validation flips to `required`) is booked separately by the parent.
     *
     * @param  string|null  $expectedTerminalId  Terminal the caller is operating, when known
     *
     * @throws HeldOrderRecallConflictException If another actor already consumed the basket
     * @throws \RuntimeException If the order lapsed and cannot be recalled
     * @throws ModelNotFoundException If order not found (or not on this terminal)
     */
    public function recallOrder(string $heldOrderId, ?string $expectedTerminalId = null): HeldOrder
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($heldOrderId, $tenantId, $companyId, $expectedTerminalId): HeldOrder {
            $query = HeldOrder::where('tenant_id', $tenantId)
                ->where('company_id', $companyId);

            if ($expectedTerminalId !== null) {
                $query->forTerminal($expectedTerminalId);
            }

            /** @var HeldOrder $heldOrder */
            $heldOrder = $query->lockForUpdate()->findOrFail($heldOrderId);

            if (! $heldOrder->canBeRecalled()) {
                if ($heldOrder->isRecalled()) {
                    // The production lost-race site on PostgreSQL: the loser's
                    // locking SELECT re-read the winner's committed row
                    // version. Same refusal as the conditional-UPDATE backstop
                    // below, so the 409 contract is true on every driver.
                    throw HeldOrderRecallConflictException::forOrder($heldOrderId);
                }
                if ($heldOrder->isExpired() || ($heldOrder->expires_at !== null && $heldOrder->expires_at->isPast())) {
                    throw new \RuntimeException('This held order has expired and cannot be recalled.');
                }
                throw new \RuntimeException('This order cannot be recalled.');
            }

            $recalledAt = now();

            $affected = HeldOrder::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($heldOrder->getKey())
                ->where('status', HeldOrderStatus::Held->value)
                ->update([
                    'status' => HeldOrderStatus::Recalled->value,
                    'recalled_at' => $recalledAt,
                    'updated_at' => $recalledAt,
                ]);

            if ($affected !== 1) {
                // Second backstop, and the only defence on SQLite: the row
                // moved out of `held` between our read and our write.
                throw HeldOrderRecallConflictException::forOrder($heldOrderId);
            }

            return $heldOrder->refresh();
        });
    }

    /**
     * Discard a held order.
     *
     * Q-8 — soft delete, not `DELETE`: the row is the only server-side trace
     * of what was parked, and a discarded basket is a discretionary,
     * money-adjacent action an auditor asks about. A RECALLED order may never
     * be discarded (its snapshot is the evidence of what was rung up); a still
     * held or expired one may.
     *
     * @param  string|null  $discardedBy  Actor performing the discard
     *
     * @throws HeldOrderDiscardRefusedException If the order's status forbids discarding
     * @throws ModelNotFoundException If order not found
     */
    public function discardOrder(string $heldOrderId, ?string $discardedBy = null): void
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        DB::transaction(function () use ($heldOrderId, $tenantId, $companyId, $discardedBy): void {
            /** @var HeldOrder $heldOrder */
            $heldOrder = HeldOrder::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($heldOrderId);

            if ($heldOrder->isRecalled()) {
                throw HeldOrderDiscardRefusedException::forStatus($heldOrderId, $heldOrder->status);
            }

            $heldOrder->forceFill(['discarded_by' => $discardedBy])->save();
            $heldOrder->delete();
        });
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
