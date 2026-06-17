import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { TrialBalancePage } from './TrialBalancePage'
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
    // The Cash row's debit cell (5,000.00) — scope to that row to disambiguate
    // from the totals row, which also shows 5,000.00.
    const cashRow = screen.getByText('Cash').closest('tr')
    if (cashRow === null) throw new Error('Cash row not found')
    const debitCell = Array.from(cashRow.querySelectorAll('td')).find(
      (td) => td.textContent === '5,000.00'
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
})
