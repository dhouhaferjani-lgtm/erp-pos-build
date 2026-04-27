import { bcadd, bcmul, bccomp, bcdiv } from '@/lib/decimal'
import type { ToleranceSettings } from '@/types/treasury'

/**
 * Frontend mirror of {@see App\Modules\Treasury\Application\Services\DiscountToleranceBoundary}
 * for inline UX feedback (cashier sees the rejection before the round-trip).
 *
 * The server is authoritative — this helper exists ONLY to give the cashier a
 * silent inline error and to grey out preset buttons whose outcome would
 * fail. Anything controversial flows through the server validator either way.
 *
 * Strict-greater inequality (discount EQUAL to margin REJECTS) — matches
 * spec §7. All math runs through `@/lib/decimal` (big.js) — never `parseFloat`
 * on monetary strings.
 *
 * Sentinel: tolerance disabled or settings missing → returns true (no
 * inline error rendered). Server still re-validates, so a stale offline
 * cache cannot bypass the check.
 */
const SCALE = 4

export function isDiscountAboveTolerance(
  discountAmount: string,
  subtotal: string,
  settings: ToleranceSettings | undefined | null,
): boolean {
  if (!settings || !settings.enabled) return true
  if (!discountAmount) return true

  const normalised = bcadd(discountAmount, '0', SCALE)
  if (bccomp(normalised, '0') === 0) return true

  const subtotalNormalised = bcadd(subtotal || '0', '0', SCALE)

  const percentageMargin = bcmul(subtotalNormalised, settings.percentage, SCALE)
  const absoluteMargin = bcadd(settings.max_amount, '0', SCALE)
  const margin = bccomp(percentageMargin, absoluteMargin) > 0
    ? percentageMargin
    : absoluteMargin

  return bccomp(normalised, margin) > 0
}

/**
 * Compute the absolute discount amount a percentage value would produce on
 * the given line/transaction subtotal. Used to disable preset buttons whose
 * computed amount would fall sub-threshold.
 *
 * Both arguments are decimal strings — Big.js accepts strings directly, so
 * there is never a reason to parseFloat through this path. Callers passing
 * UI input strings can rely on the bcdiv/bcmul fallbacks for empty input.
 */
export function computeDiscountAmount(
  subtotal: string,
  percentage: string,
): string {
  const pctString = bcdiv(percentage || '0', '100', SCALE)
  return bcmul(subtotal || '0', pctString, SCALE)
}
