import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ExpenseAnalyticsPage } from './ExpenseAnalyticsPage'

const mockUseExpenseAnalytics = vi.hoisted(() => vi.fn<(filters: unknown) => unknown>())
const mockExportCsv = vi.hoisted(() => vi.fn<(filters: unknown) => Promise<unknown>>())
const mockHasPermission = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../hooks/useExpenses', () => ({
  useExpenseAnalytics: (filters: unknown): unknown => mockUseExpenseAnalytics(filters),
}))

vi.mock('../api/expenseApi', () => ({
  expenseApi: {
    exportCsv: (filters: unknown): Promise<unknown> => mockExportCsv(filters),
  },
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'TND' }),
}))

vi.mock('../components/molecules/ExpenseCategorySelect', () => ({
  ExpenseCategorySelect: ({ onChange }: { onChange: (value: string) => void }) => (
    <button type="button" onClick={() => { onChange('category-1') }}>
      expense-category-filter
    </button>
  ),
}))

const analytics = {
  tiles: {
    total: '600.000',
    count: 3,
    unpaid_total: '120.000',
    mom_delta_percent: '-10.50',
  },
  by_category: [
    { category_id: 'category-1', name: 'Rent', total: '400.000', share_percent: '66.67' },
  ],
  matrix: [
    {
      category_id: 'category-1',
      name: 'Rent',
      months: { '2026-01': '100.000', '2026-02': '300.000' },
    },
    {
      category_id: null,
      name: 'Uncategorized',
      months: { '2026-02': '200.000' },
    },
  ],
  top_vendors: [
    { partner_id: 'partner-1', vendor_name: 'Office SA', total: '420.000' },
  ],
}

beforeEach(() => {
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: vi.fn(),
  })
  Object.defineProperty(URL, 'revokeObjectURL', {
    configurable: true,
    value: vi.fn(),
  })
  vi.clearAllMocks()
  mockHasPermission.mockReturnValue(true)
  mockUseExpenseAnalytics.mockReturnValue({ data: analytics, isLoading: false, error: null })
  mockExportCsv.mockResolvedValue({ data: new Blob(['csv']), headers: {} })
})

describe('ExpenseAnalyticsPage', () => {
  it('renders string-precise category-by-month analytics in an RTL-safe overflow region', () => {
    render(<ExpenseAnalyticsPage />)

    const matrix = screen.getByTestId('expense-analytics-matrix')
    expect(matrix).toHaveClass('overflow-x-auto')
    expect(matrix).toHaveAttribute('dir', 'auto')
    expect(within(matrix).getByText('2026-01')).toBeInTheDocument()
    expect(within(matrix).getByText('2026-02')).toBeInTheDocument()
    expect(within(matrix).getByText('100,000 TND')).toBeInTheDocument()
    expect(within(matrix).getByText('300,000 TND')).toBeInTheDocument()
    expect(within(matrix).getByText('200,000 TND')).toBeInTheDocument()
    expect(screen.getByText('expenses:analytics.legacyNetCaption')).toBeInTheDocument()
    expect(screen.getByText('Office SA')).toBeInTheDocument()
  })

  it('filters by date, category, and status and never introduces search', () => {
    const { container } = render(<ExpenseAnalyticsPage />)
    const dateInputs = container.querySelectorAll<HTMLInputElement>('input[type="date"]')

    fireEvent.change(dateInputs.item(0), { target: { value: '2026-01-01' } })
    fireEvent.change(dateInputs.item(1), { target: { value: '2026-02-28' } })
    fireEvent.click(screen.getByText('expense-category-filter'))
    fireEvent.change(screen.getByLabelText('expenses:filters.status'), {
      target: { value: 'draft' },
    })

    expect(mockUseExpenseAnalytics).toHaveBeenLastCalledWith({
      category_id: 'category-1',
      date_from: '2026-01-01',
      date_to: '2026-02-28',
      status: 'draft',
    })
  })

  it('binds the displayed default and export to posted status', async () => {
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: vi.fn(() => 'blob:posted-export'),
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: vi.fn(),
    })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<ExpenseAnalyticsPage />)

    expect(mockUseExpenseAnalytics).toHaveBeenLastCalledWith({ status: 'posted' })
    fireEvent.click(screen.getByText('expenses:analytics.export'))
    await waitFor(() => {
      expect(mockExportCsv).toHaveBeenCalledWith({ status: 'posted' })
    })
  })

  it('gates export and cleans up its object URL', async () => {
    mockHasPermission.mockReturnValue(false)
    const denied = render(<ExpenseAnalyticsPage />)
    expect(screen.queryByText('expenses:analytics.export')).not.toBeInTheDocument()
    denied.unmount()

    mockHasPermission.mockReturnValue(true)
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:analytics-export')
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<ExpenseAnalyticsPage />)
    fireEvent.click(screen.getByText('expenses:analytics.export'))

    await waitFor(() => { expect(mockExportCsv).toHaveBeenCalledWith({ status: 'posted' }) })
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:analytics-export')

    createObjectURL.mockRestore()
    revokeObjectURL.mockRestore()
    click.mockRestore()
  })
})
