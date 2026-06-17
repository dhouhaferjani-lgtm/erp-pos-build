import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import { ProfitLossPage } from './ProfitLossPage'
import type { ProfitLossData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown): string =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: ({ title }: { title: string }) => <div>{title}</div>,
}))

const mockUseProfitLoss = vi.fn()
vi.mock('../hooks/useProfitLoss', () => ({
  useProfitLoss: () => mockUseProfitLoss() as unknown,
}))

const fixture: ProfitLossData = {
  revenue: [
    { account_code: '700', account_name: 'Sales', amount: '1000.00' },
  ],
  expenses: [
    { account_code: '600', account_name: 'Purchases', amount: '400.00' },
  ],
  total_revenue: '1000.00',
  total_expenses: '400.00',
  net_income: '600.00',
}

beforeEach(() => {
  mockUseProfitLoss.mockReturnValue({
    data: fixture,
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
})

describe('ProfitLossPage canonicalization', () => {
  it('renders exactly one canonical <h1> page title', () => {
    const { container } = render(<ProfitLossPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('reports.profitLossReport.title')
  })

  it('renders the export control as a <button>', () => {
    const { container } = render(<ProfitLossPage />)
    const buttons = container.querySelectorAll('button')
    expect(buttons.length).toBeGreaterThan(0)
  })

  it('right-aligns numeric cells with tabular-nums', () => {
    const { container } = render(<ProfitLossPage />)
    const cells = Array.from(container.querySelectorAll('td'))
    const numericCell = cells.find(
      (c) =>
        c.className.includes('tabular-nums') && c.className.includes('text-end'),
    )
    expect(numericCell).toBeDefined()
  })
})
