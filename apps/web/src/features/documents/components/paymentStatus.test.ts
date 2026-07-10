import { describe, expect, it } from 'vitest'

import { isPaymentStatus, paymentStatusFallbackLabel, paymentStatusTone } from './paymentStatus'

describe('paymentStatus helpers', () => {
  it('recognizes supported payment statuses only', () => {
    expect(isPaymentStatus('unpaid')).toBe(true)
    expect(isPaymentStatus('partially_paid')).toBe(true)
    expect(isPaymentStatus('in_payment')).toBe(true)
    expect(isPaymentStatus('paid')).toBe(true)
    expect(isPaymentStatus('overpaid')).toBe(true)

    expect(isPaymentStatus('draft')).toBe(false)
    expect(isPaymentStatus('')).toBe(false)
    expect(isPaymentStatus(null)).toBe(false)
    expect(isPaymentStatus(undefined)).toBe(false)
  })

  it('maps payment statuses to semantic tones and fallback labels', () => {
    expect(paymentStatusTone('unpaid')).toBe('danger')
    expect(paymentStatusTone('partially_paid')).toBe('warning')
    expect(paymentStatusTone('in_payment')).toBe('info')
    expect(paymentStatusTone('paid')).toBe('success')
    expect(paymentStatusTone('overpaid')).toBe('warning')

    expect(paymentStatusFallbackLabel('in_payment')).toBe('In Payment')
  })
})
