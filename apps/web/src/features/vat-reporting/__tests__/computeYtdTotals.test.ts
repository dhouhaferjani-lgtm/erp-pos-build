import { computeYtdTotals } from '../computeYtdTotals'
import type { VatPeriod } from '../types'

function makePeriod(overrides: Partial<VatPeriod>): VatPeriod {
  return {
    id: overrides.id ?? 'period-1',
    label: overrides.label ?? 'Period',
    period_type: overrides.period_type ?? 'MONTHLY',
    period_start: overrides.period_start ?? '2026-01-01',
    period_end: overrides.period_end ?? '2026-01-31',
    status: overrides.status ?? 'CLOSED',
    total_output_vat: overrides.total_output_vat ?? '0.000',
    total_input_vat: overrides.total_input_vat ?? '0.000',
    net_vat: overrides.net_vat ?? '0.000',
    credit_brought_forward: overrides.credit_brought_forward ?? '0.000',
    credit_carried_forward: overrides.credit_carried_forward ?? '0.000',
    amount_payable: overrides.amount_payable ?? '0.000',
    closed_at: overrides.closed_at ?? null,
    filed_at: overrides.filed_at ?? null,
    filing_reference: overrides.filing_reference ?? null,
  }
}

describe('computeYtdTotals', () => {
  it('returns zero totals for an empty period list', () => {
    const ytd = computeYtdTotals([])
    expect(ytd.outputVat).toBe('0')
    expect(ytd.inputVat).toBe('0')
    expect(ytd.creditBroughtForward).toBe('0')
    expect(ytd.amountPayable).toBe('0')
  })

  /**
   * MINOR-1 (2026-08-07 FE gate,
   * docs/superpowers/reviews/2026-08-07-r2g-fe-gate.md): the fold used to
   * round the running total to the DISPLAY currency's scale (2 for EUR) on
   * EVERY iteration and carry the rounded value forward, compounding a
   * sub-cent drift. Three periods at 100.005:
   * - round-PER-STEP (the bug): 100.005 -> 100.01, +100.005 -> 200.015 ->
   *   200.02, +100.005 -> 300.025 -> 300.03.
   * - round-ONCE (the fix): sum exactly at the canonical scale-3 precision
   *   (300.015), defer the single rounding to render time -- which would
   *   correctly produce 300.02 (half-up on the 3rd decimal), NOT 300.03.
   * This pins that the fold itself returns the EXACT, un-rounded scale-3
   * sum -- the 1-cent discrepancy class the gate found is structurally
   * impossible once the fold never rounds mid-accumulation.
   */
  it('accumulates at full precision without rounding on every step (round-once, not round-per-step)', () => {
    const periods = [
      makePeriod({ id: 'p1', total_output_vat: '100.005', total_input_vat: '10.001' }),
      makePeriod({ id: 'p2', total_output_vat: '100.005', total_input_vat: '10.001' }),
      makePeriod({ id: 'p3', total_output_vat: '100.005', total_input_vat: '10.001' }),
    ]

    const ytd = computeYtdTotals(periods)

    // Exact scale-3 sum -- NOT the drifted '300.03' a round-per-step-at-2dp
    // fold would have produced (100.01 -> 200.02 -> 300.03).
    expect(ytd.outputVat).toBe('300.015')
    expect(ytd.inputVat).toBe('30.003')
  })

  it('treats a null total_output_vat/total_input_vat as zero without throwing', () => {
    const periods = [
      makePeriod({ id: 'p1', total_output_vat: null, total_input_vat: '5.000' }),
      makePeriod({ id: 'p2', total_output_vat: '5.000', total_input_vat: null }),
    ]

    const ytd = computeYtdTotals(periods)

    expect(ytd.outputVat).toBe('5.000')
    expect(ytd.inputVat).toBe('5.000')
  })

  it('uses the latest period (by period_end) for credit/payable, independent of array order', () => {
    const periods = [
      makePeriod({
        id: 'jan',
        period_end: '2026-01-31',
        credit_carried_forward: '10.000',
        amount_payable: '1.000',
      }),
      makePeriod({
        id: 'mar',
        period_end: '2026-03-31',
        credit_carried_forward: '30.000',
        amount_payable: '3.000',
      }),
      makePeriod({
        id: 'feb',
        period_end: '2026-02-28',
        credit_carried_forward: '20.000',
        amount_payable: '2.000',
      }),
    ]

    const ytd = computeYtdTotals(periods)

    expect(ytd.creditBroughtForward).toBe('30.000')
    expect(ytd.amountPayable).toBe('3.000')
  })
})
