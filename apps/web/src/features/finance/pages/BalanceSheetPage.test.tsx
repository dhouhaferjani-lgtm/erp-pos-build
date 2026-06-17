import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import { BalanceSheetPage } from './BalanceSheetPage'
import type { BalanceSheetData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown): string =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: ({ title }: { title: string }) => <div>{title}</div>,
}))

const mockUseBalanceSheet = vi.fn()
vi.mock('../hooks/useBalanceSheet', () => ({
  useBalanceSheet: () => mockUseBalanceSheet() as unknown,
}))

const fixture: BalanceSheetData = {
  assets: [{ account_code: '512', account_name: 'Bank', amount: '5000.00' }],
  liabilities: [
    { account_code: '401', account_name: 'Suppliers', amount: '1200.00' },
  ],
  equity: [{ account_code: '101', account_name: 'Capital', amount: '3800.00' }],
  total_assets: '5000.00',
  total_liabilities: '1200.00',
  total_equity: '3800.00',
}

beforeEach(() => {
  mockUseBalanceSheet.mockReturnValue({
    data: fixture,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
})

describe('BalanceSheetPage canonicalization', () => {
  it('renders exactly one canonical <h1> page title', () => {
    const { container } = render(<BalanceSheetPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('reports.balanceSheetReport.title')
  })

  it('renders the export control as a <button>', () => {
    const { container } = render(<BalanceSheetPage />)
    const buttons = container.querySelectorAll('button')
    expect(buttons.length).toBeGreaterThan(0)
  })

  it('right-aligns numeric cells with tabular-nums', () => {
    const { container } = render(<BalanceSheetPage />)
    const cells = Array.from(container.querySelectorAll('td'))
    const numericCell = cells.find(
      (c) =>
        c.className.includes('tabular-nums') && c.className.includes('text-end'),
    )
    expect(numericCell).toBeDefined()
  })
})
