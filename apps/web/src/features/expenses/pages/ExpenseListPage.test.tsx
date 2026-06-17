import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

import { ExpenseListPage } from './ExpenseListPage'

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
const mockDeleteMutate = vi.fn()
const mockPostMutate = vi.fn()
vi.mock('../hooks/useExpenses', () => ({
  useExpenses: () => mockUseExpenses() as unknown,
  useDeleteExpense: () => ({ mutate: mockDeleteMutate }),
  usePostExpense: () => ({ mutate: mockPostMutate }),
}))

// ─── child components → stubs so the test isolates the page shell ─────────────
vi.mock('../components/organisms/ExpenseList', () => ({
  ExpenseList: () => <div data-testid="expense-list" />,
}))
vi.mock('../components/molecules/ExpenseCategorySelect', () => ({
  ExpenseCategorySelect: () => <div data-testid="expense-category-select" />,
}))

beforeEach(() => {
  mockNavigate.mockReset()
  mockUseExpenses.mockReset()
  mockUseExpenses.mockReturnValue({ data: { data: [] }, isLoading: false })
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
})
