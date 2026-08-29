import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import i18n from '@/lib/i18n'
import { CreditLimitWarning } from './CreditLimitWarning'

describe('CreditLimitWarning decimal boundaries', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  afterEach(async () => {
    cleanup()
    await i18n.changeLanguage('en')
  })

  it('treats an equal TND balance as limit reached and keeps all three decimals', () => {
    render(
      <CreditLimitWarning
        creditLimit="1000.000"
        outstandingBalance="1000.000"
        currency="TND"
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Credit limit exceeded')
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Outstanding: 1 000,000 TND / Limit: 1 000,000 TND (100% used)',
    )
  })

  it('detects a balance exactly one millime over the TND limit', () => {
    render(
      <CreditLimitWarning
        creditLimit="1000.000"
        outstandingBalance="1000.001"
        currency="TND"
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Credit limit exceeded')
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Outstanding: 1 000,001 TND',
    )
  })

  it('renders a three-decimal TND approaching-limit message without cent rounding', () => {
    render(
      <CreditLimitWarning
        creditLimit="1000.500"
        outstandingBalance="800.400"
        currency="TND"
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Approaching credit limit')
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Outstanding: 800,400 TND / Limit: 1 000,500 TND (80% used)',
    )
  })

  it('truncates displayed usage so a below-limit balance never reads as 100 percent', () => {
    render(
      <CreditLimitWarning
        creditLimit="1000.000"
        outstandingBalance="999.500"
        currency="TND"
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Approaching credit limit')
    expect(screen.getByRole('alert')).toHaveTextContent('(99% used)')
    expect(screen.getByRole('alert')).not.toHaveTextContent('(100% used)')
  })

  it('uses Arabic credit-warning copy instead of the English fallback', async () => {
    await i18n.changeLanguage('ar')

    render(
      <CreditLimitWarning
        creditLimit="1000.000"
        outstandingBalance="1000.001"
        currency="TND"
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('تم تجاوز حد الائتمان')
    expect(screen.getByRole('alert')).toHaveTextContent('المستحق:')
    expect(screen.getByRole('alert')).not.toHaveTextContent('Credit limit exceeded')
  })
})
