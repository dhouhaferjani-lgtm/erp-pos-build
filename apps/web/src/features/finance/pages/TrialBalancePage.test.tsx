import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { TrialBalancePage } from './TrialBalancePage'
import { formatCurrency } from '../../../lib/format'
import type { TrialBalanceData } from '../types'

// i18n mock: echo the interpolation string when given, else the key.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// QueryError stub (avoids pulling its i18n / icon deps into this unit test).
vi.mock('@/components/QueryError', () => ({
  QueryError: ({ title }: { title: string }) => <div>{title}</div>,
}))

const { mockUseTrialBalance } = vi.hoisted(() => ({
  mockUseTrialBalance: vi.fn(),
}))

vi.mock('../hooks/useTrialBalance', () => ({
  useTrialBalance: mockUseTrialBalance,
}))

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: { currency: 'TND', locale: 'fr_TN' },
  }),
}))

const fixture: TrialBalanceData = {
  lines: [
    {
      account_code: '1000',
      account_name: 'Cash',
      account_type: 'asset',
      debit: '5000.00',
      credit: '0.00',
      level: 0,
      is_parent: false,
    },
    {
      account_code: '3000',
      account_name: 'Capital',
      account_type: 'equity',
      debit: '0.00',
      credit: '5000.00',
      level: 0,
      is_parent: false,
    },
  ],
  total_debit: '5000.00',
  total_credit: '5000.00',
  is_balanced: true,
  as_of_date: '2026-06-14',
}

function normalizeSpaces(value: string): string {
  return value.replace(/\s/g, ' ')
}

describe('TrialBalancePage (design-system)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseTrialBalance.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
  })

  it('renders exactly one <h1>', () => {
    const { container } = render(<TrialBalancePage />)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('right-aligns numeric cells with tabular-nums', () => {
    render(<TrialBalancePage />)
    // The Cash row's debit cell — scope to that row to disambiguate
    // from the totals row, which also shows the same amount.
    const cashRow = screen.getByText('Cash').closest('tr')
    if (cashRow === null) throw new Error('Cash row not found')
    const debitCell = Array.from(cashRow.querySelectorAll('td')).find(
      (td) => {
        const text = td.textContent
        return text.includes('5') && text.includes('TND')
      }
    )
    if (debitCell === undefined) throw new Error('debit cell not found')
    expect(debitCell.className).toContain('tabular-nums')
    expect(debitCell.className).toContain('text-end')
  })

  it('renders the export control as a <button>', () => {
    render(<TrialBalancePage />)
    const exportEl = screen.getByText('finance:reports.common.export')
    expect(exportEl.closest('button')).not.toBeNull()
  })

  it('formats money with the selected company currency without US formatting', () => {
    render(<TrialBalancePage />)

    const formattedDebit = formatCurrency('5000.00', {
      currency: 'TND',
      locale: 'fr-TN',
    })
    expect(normalizeSpaces(document.body.textContent)).toContain(
      normalizeSpaces(formattedDebit)
    )
    expect(screen.queryByText('5,000.00')).not.toBeInTheDocument()
  })

  it('defaults the as-of date to today', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-15T12:00:00.000Z'))
    try {
      render(<TrialBalancePage />)

      expect(screen.getByLabelText('finance:reports.common.asOfDate')).toHaveValue('2026-07-15')
    } finally {
      vi.useRealTimers()
    }
  })
})
