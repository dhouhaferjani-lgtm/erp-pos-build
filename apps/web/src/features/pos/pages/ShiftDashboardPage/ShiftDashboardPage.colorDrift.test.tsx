import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { ShiftDashboardPage, type Shift, type Terminal } from './ShiftDashboardPage'

// Lightweight i18n mock: return the interpolation default string when provided,
// otherwise echo the key. Keeps the test focused on presentation/tokenization.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 2 }),
}))

const terminal: Terminal = {
  id: 'terminal-1',
  code: 'POS-01',
  location_id: 'loc-1',
  location_name: 'Main Store',
}

const shift: Shift = {
  id: 'shift-1',
  terminal_id: 'terminal-1',
  shift_number: 42,
  cashier_id: 'user-1',
  cashier_name: 'John Doe',
  opening_cash: '500.00',
  expected_cash: '1250.00',
  opened_at: '2026-01-09T08:00:00Z',
  status: 'OPEN',
}

function renderPage() {
  return render(
    <ShiftDashboardPage
      currentShift={shift}
      terminal={terminal}
      onOpenShift={vi.fn()}
      onCloseShift={vi.fn()}
      onGenerateXReport={vi.fn()}
      onCashDeposit={vi.fn()}
      onCashPayout={vi.fn()}
    />,
  )
}

describe('ShiftDashboardPage — color-drift / tokenization', () => {
  it('renders the terminal title', () => {
    const { getByText } = renderPage()
    expect(getByText('POS-01')).toBeInTheDocument()
  })

  it('renders the open-shift status via StatusBadge (rounded-full pill)', () => {
    const { getByText } = renderPage()
    const badge = getByText('pos:shiftDashboard.statusOpen')
    expect(badge.className).toContain('rounded-full')
  })

  it('right-aligns money figures with tabular-nums', () => {
    const { getByText } = renderPage()
    const expectedCash = getByText('1250.00')
    expect(expectedCash.className).toContain('tabular-nums')
    expect(expectedCash.className).toContain('text-right')
  })
})
