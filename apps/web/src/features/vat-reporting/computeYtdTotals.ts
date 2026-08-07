import { bcadd } from '@/lib/decimal'
import type { VatPeriod } from './types'

/**
 * m-6 (2026-08-06 gate): accumulate with bcadd (Big.js, never a float `+=`
 * off a parseFloat'd value) -- rule 19.
 *
 * MINOR-1 (2026-08-07 FE gate,
 * docs/superpowers/reviews/2026-08-07-r2g-fe-gate.md): round ONCE, at
 * format, not at every fold step. bcadd's default scale (3, the canonical
 * storage scale for money columns) keeps full precision through the
 * accumulation regardless of the display currency's own scale -- rounding
 * to a 2dp currency's scale on EVERY iteration would compound a sub-cent
 * drift across many periods (precision-contract: round once at the
 * boundary). `useCurrency().format()` performs the single, final rounding
 * to the display scale when this total is rendered.
 *
 * Lives in its own module (not inlined in VatPeriodsPage.tsx) so it can be
 * unit-tested directly without rendering the page, and so exporting it
 * doesn't trip `react-refresh/only-export-components` on a page file.
 */
export function computeYtdTotals(periods: VatPeriod[]) {
  let totalOutput = '0'
  let totalInput = '0'
  let creditCarried = '0'
  let amountPayable = '0'

  for (const period of periods) {
    totalOutput = bcadd(totalOutput, period.total_output_vat ?? '0')
    totalInput = bcadd(totalInput, period.total_input_vat ?? '0')
  }

  // Use the most recent period's credit/payable as the running total.
  // Periods are ordered by period_start DESC, so index 0 is the latest.
  if (periods.length > 0) {
    const latestPeriod = periods.reduce((latest, p) =>
      p.period_end > latest.period_end ? p : latest
    , periods[0])
    creditCarried = latestPeriod.credit_carried_forward
    amountPayable = latestPeriod.amount_payable
  }

  return {
    outputVat: totalOutput,
    inputVat: totalInput,
    creditBroughtForward: creditCarried,
    amountPayable,
  }
}
