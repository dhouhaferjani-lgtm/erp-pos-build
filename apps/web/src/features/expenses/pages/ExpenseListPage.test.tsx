import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'

import { ExpenseListPage } from './ExpenseListPage'
import { formatCurrency } from '@/lib/format'

// ─── i18n: echo key, or return the string-interpolation arg when present ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── react-router-dom: capture navigate, render Link as a plain anchor ────────
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

// ─── expenses hooks → tiny fixtures (presentation-only isolation) ─────────────
const mockUseExpenses = vi.fn()
const mockUseExpenseAnalytics = vi.fn()
const mockDeleteMutate = vi.fn()
const mockPostMutate = vi.fn()
const mockExportCsv = vi.fn()
const mockHasPermission = vi.fn()
const mockAnalyticsRefetch = vi.fn()
vi.mock('../hooks/useExpenses', () => ({
  useExpenses: (filters: unknown) => mockUseExpenses(filters) as unknown,
  useExpenseAnalytics: (filters: unknown) => mockUseExpenseAnalytics(filters) as unknown,
  useDeleteExpense: () => ({ mutate: mockDeleteMutate }),
  usePostExpense: () => ({ mutate: mockPostMutate }),
}))
vi.mock('../api/expenseApi', () => ({
  expenseApi: { exportCsv: (filters: unknown) => mockExportCsv(filters) as unknown },
}))
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'TND' }),
}))

// ─── child components → stubs so the test isolates the page shell ─────────────
vi.mock('../components/organisms/ExpenseList', () => ({
  ExpenseList: () => <div data-testid="expense-list" />,
}))
vi.mock('../components/molecules/ExpenseCategorySelect', () => ({
  ExpenseCategorySelect: ({ onChange }: { onChange: (value: string) => void }) => (
    <button type="button" onClick={() => { onChange('category-1') }}>
      expense-category-select
    </button>
  ),
}))

beforeEach(() => {
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: vi.fn(),
  })
  Object.defineProperty(URL, 'revokeObjectURL', {
    configurable: true,
    value: vi.fn(),
  })
  mockNavigate.mockReset()
  mockUseExpenses.mockReset()
  mockUseExpenses.mockReturnValue({
    data: { data: [] },
    isLoading: false,
  })
  mockUseExpenseAnalytics.mockReset()
  mockAnalyticsRefetch.mockReset()
  mockUseExpenseAnalytics.mockReturnValue({
    data: {
      tiles: {
        total: '1234.567',
        count: 4,
        unpaid_total: '234.500',
        mom_delta_percent: '8.25',
      },
      by_category: [],
      matrix: [],
      top_vendors: [],
    },
    isLoading: false,
    error: null,
    refetch: mockAnalyticsRefetch,
  })
  mockExportCsv.mockReset()
  mockExportCsv.mockResolvedValue({
    data: new Blob(['csv']),
    headers: { 'content-disposition': 'attachment; filename="expenses-filtered.csv"' },
  })
  mockHasPermission.mockReset()
  mockHasPermission.mockReturnValue(true)
})

describe('ExpenseListPage shell', () => {
  it('renders exactly one <h1>', () => {
    render(<ExpenseListPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a search control', () => {
    render(<ExpenseListPage />)
    expect(
      screen.getByPlaceholderText('expenses:searchPlaceholder')
    ).toBeInTheDocument()
  })

  it('navigates to /expenses/new when the Add control is clicked', () => {
    render(<ExpenseListPage />)
    fireEvent.click(screen.getByText('expenses:createExpense'))
    expect(mockNavigate).toHaveBeenCalledWith('/expenses/new')
  })

  it('renders the ExpenseList body organism', () => {
    render(<ExpenseListPage />)
    expect(screen.getByTestId('expense-list')).toBeInTheDocument()
  })

  it('announces the list analytics loading state accessibly', () => {
    mockUseExpenseAnalytics.mockReturnValue({
      data: undefined,
      isLoading: true,
      error: null,
      refetch: mockAnalyticsRefetch,
    })

    render(<ExpenseListPage />)

    expect(screen.getByRole('status')).toHaveTextContent('expenses:analytics.loading')
    expect(screen.getByTestId('expense-list')).toBeInTheDocument()
  })

  it('shows permanent list analytics errors instead of empty-value tiles', () => {
    mockUseExpenseAnalytics.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('Network unavailable'),
      refetch: mockAnalyticsRefetch,
    })

    render(<ExpenseListPage />)

    expect(screen.getByRole('heading', { name: 'expenses:analytics.loadError' }))
      .toBeInTheDocument()
    expect(screen.getByText('Network unavailable')).toBeInTheDocument()
    expect(screen.getByTestId('expense-list')).toBeInTheDocument()
  })

  it('retries failed list analytics from the error action', () => {
    mockUseExpenseAnalytics.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('Network unavailable'),
      refetch: mockAnalyticsRefetch,
    })

    render(<ExpenseListPage />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.tryAgain' }))

    expect(mockAnalyticsRefetch).toHaveBeenCalledOnce()
  })

  it('starts list, tiles, and export at posted, then maps explicit All coherently', async () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    render(<ExpenseListPage />)

    expect(mockUseExpenses).toHaveBeenLastCalledWith({ status: 'posted' })
    expect(mockUseExpenseAnalytics).toHaveBeenLastCalledWith({ status: 'posted' })

    fireEvent.change(screen.getByLabelText('expenses:filters.status'), {
      target: { value: '' },
    })

    await waitFor(() => {
      expect(mockUseExpenses).toHaveBeenLastCalledWith({})
      expect(mockUseExpenseAnalytics).toHaveBeenLastCalledWith({ status: 'all' })
    })

    fireEvent.click(screen.getByText('expenses:analytics.export'))
    await waitFor(() => { expect(mockExportCsv).toHaveBeenCalledWith({}) })
    click.mockRestore()
  })

  it('threads both date endpoints into the list and analytics while omitting search from analytics', async () => {
    const { container } = render(<ExpenseListPage />)
    const dateInputs = container.querySelectorAll<HTMLInputElement>('input[type="date"]')

    expect(dateInputs).toHaveLength(2)
    fireEvent.change(dateInputs.item(0), { target: { value: '2026-01-01' } })
    fireEvent.change(dateInputs.item(1), { target: { value: '2026-03-31' } })
    fireEvent.change(screen.getByPlaceholderText('expenses:searchPlaceholder'), {
      target: { value: 'paper' },
    })
    await waitFor(() => {
      expect(mockUseExpenses).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'paper' }))
    })
    fireEvent.click(screen.getByText('expense-category-select'))

    await waitFor(() => {
      expect(mockUseExpenses).toHaveBeenLastCalledWith({
        category_id: 'category-1',
        date_from: '2026-01-01',
        date_to: '2026-03-31',
        search: 'paper',
        status: 'posted',
      })
    })
    expect(mockUseExpenseAnalytics).toHaveBeenLastCalledWith({
      category_id: 'category-1',
      date_from: '2026-01-01',
      date_to: '2026-03-31',
      status: 'posted',
    })
    expect(screen.getByText('expenses:analytics.searchExcluded')).toBeInTheDocument()
  })

  it('formats money tiles with the company currency', () => {
    render(<ExpenseListPage />)

    expect(screen.getByText('expenses:analytics.tiles.total').nextElementSibling?.textContent)
      .toBe(formatCurrency('1234.567', { currency: 'TND' }))
    expect(screen.getByText('expenses:analytics.tiles.unpaid').nextElementSibling?.textContent)
      .toBe(formatCurrency('234.500', { currency: 'TND' }))
  })

  it('hides export without expenses.export and downloads an authenticated blob when allowed', async () => {
    mockHasPermission.mockReturnValue(false)
    const denied = render(<ExpenseListPage />)
    expect(screen.queryByText('expenses:analytics.export')).not.toBeInTheDocument()
    denied.unmount()

    mockHasPermission.mockReturnValue(true)
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:expense-export')
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<ExpenseListPage />)
    fireEvent.change(screen.getByPlaceholderText('expenses:searchPlaceholder'), {
      target: { value: 'paper' },
    })
    await waitFor(() => {
      expect(mockUseExpenses).toHaveBeenLastCalledWith({ search: 'paper', status: 'posted' })
    })
    fireEvent.click(screen.getByText('expenses:analytics.export'))

    await waitFor(() => {
      expect(mockExportCsv).toHaveBeenCalledWith({ search: 'paper', status: 'posted' })
    })
    expect(createObjectURL).toHaveBeenCalledOnce()
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:expense-export')

    createObjectURL.mockRestore()
    revokeObjectURL.mockRestore()
    click.mockRestore()
  })
})
