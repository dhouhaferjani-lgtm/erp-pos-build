import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CashTenderedModal } from './CashTenderedModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

// Deterministic currency so formatted money strings are stable in jsdom.
// `getDecimals` is also consumed by the web MoneyInput atom this modal renders.
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (value: string | number) => `€${Number(value).toFixed(2)}`,
  }),
  getDecimals: () => 2,
}))

describe('CashTenderedModal (color-drift)', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    total: '12.00',
    isProcessing: false,
  }

  it('renders the amount due and tendered figures', () => {
    render(<CashTenderedModal {...defaultProps} />)

    expect(screen.getByText('pos:cashTendered.amountDue')).toBeInTheDocument()
    expect(screen.getByText('€12.00')).toBeInTheDocument()
    expect(
      screen.getByText('pos:cashTendered.tenderedAmount')
    ).toBeInTheDocument()
  })

  it('renders denomination shortcuts including exact amount', () => {
    render(<CashTenderedModal {...defaultProps} />)

    expect(
      screen.getByRole('button', { name: 'pos:cashTendered.exactAmount' })
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: '€50.00' })
    ).toBeInTheDocument()
  })

  it('renders the confirm action', () => {
    render(<CashTenderedModal {...defaultProps} />)

    expect(
      screen.getByRole('button', { name: /pos:cashTendered.confirm/ })
    ).toBeInTheDocument()
  })
})
