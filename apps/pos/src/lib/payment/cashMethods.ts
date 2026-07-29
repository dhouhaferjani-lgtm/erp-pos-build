/**
 * Cash-ness over the cached payment methods — pure, store-free.
 *
 * Lives here rather than in `paymentStore` because BOTH the gate (the store)
 * and the tender UI (`AdvancedPaymentsModal`, `HomePage`) have to answer the
 * same question, and a component reaching into a store module for a pure
 * predicate is how the two ends of a gate drift apart.
 */

import type { PaymentMethod } from '@/types/payment';

/**
 * Cash-ness resolver over the cached payment methods. `is_cash_tender` is the
 * ONE predicate (spec §4.1); the legacy `is_physical && !has_maturity` shape
 * classified MEAL_VOUCHER as cash.
 *
 * Fail-closed by construction: migration v63 adds `is_cash_tender` with
 * `DEFAULT 0` and no backfill, so a device that has migrated but never pulled
 * `/payment-methods` resolves NOTHING as cash — no rounding, no auto-accept,
 * and quick cash surfaces `errors.noCashMethod` rather than guessing.
 */
export function makeIsCashMethodCode(
  methods: readonly PaymentMethod[],
): (code: string) => boolean {
  const cashCodes = new Set<string>();
  for (const method of methods) {
    if (method.is_cash_tender && method.is_active) {
      cashCodes.add(method.code);
    }
  }
  return (code: string) => cashCodes.has(code);
}
