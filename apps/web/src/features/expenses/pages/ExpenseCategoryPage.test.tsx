import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'

// ─── i18n: echo key ───────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── expense category hooks ───────────────────────────────────────────────────
const mockCreateMutateAsync = vi.fn()
const mockUseExpenseCategories = vi.fn()

vi.mock('../hooks/useExpenseCategories', () => ({
  useExpenseCategories: () => mockUseExpenseCategories() as unknown,
  useCreateExpenseCategory: () => ({
    mutateAsync: mockCreateMutateAsync,
    isPending: false,
  }),
  useUpdateExpenseCategory: () => ({
    mutateAsync: vi.fn(),
    isPending: false,
  }),
  useDeleteExpenseCategory: () => ({ mutate: vi.fn() }),
}))

// ─── finance accounts hook ────────────────────────────────────────────────────
const mockUseAccounts = vi.fn()

vi.mock('../../finance/hooks/useAccounts', () => ({
  useAccounts: (filters?: unknown) => mockUseAccounts(filters) as unknown,
}))

const MOCK_ACCOUNTS = [
  {
    id: 'acc-601',
    code: '601',
    name: 'Purchases of goods',
    type: 'expense' as const,
    tenant_id: 't1',
    parent_id: null,
    description: null,
    is_active: true,
    is_system: false,
    balance: '0',
    created_at: '',
    updated_at: '',
  },
  {
    id: 'acc-606',
    code: '606',
    name: 'General supplies',
    type: 'expense' as const,
    tenant_id: 't1',
    parent_id: null,
    description: null,
    is_active: true,
    is_system: false,
    balance: '0',
    created_at: '',
    updated_at: '',
  },
]

beforeEach(() => {
  vi.clearAllMocks()
  mockUseExpenseCategories.mockReturnValue({ data: [], isLoading: false })
  mockUseAccounts.mockReturnValue({ data: MOCK_ACCOUNTS, isLoading: false })
  mockCreateMutateAsync.mockResolvedValue({})
})

import { ExpenseCategoryPage } from './ExpenseCategoryPage'

describe('ExpenseCategoryPage — GL account picker', () => {
  it('renders the page with a heading', () => {
    render(<ExpenseCategoryPage />)
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument()
  })

  it('renders a GL-account select in the create form', () => {
    render(<ExpenseCategoryPage />)
    fireEvent.click(screen.getByRole('button', { name: 'expenses:categories.createCategory' }))
    expect(screen.getByLabelText('expenses:categories.form.glAccount')).toBeInTheDocument()
  })

  it('lists expense accounts as options in the GL select', () => {
    render(<ExpenseCategoryPage />)
    fireEvent.click(screen.getByRole('button', { name: 'expenses:categories.createCategory' }))
    const glSelect = screen.getByLabelText('expenses:categories.form.glAccount')
    expect(glSelect).toContainElement(screen.getByText('601 – Purchases of goods'))
    expect(glSelect).toContainElement(screen.getByText('606 – General supplies'))
  })

  it('submits account_id when a GL account is chosen', async () => {
    render(<ExpenseCategoryPage />)

    // Open the create form
    fireEvent.click(screen.getByRole('button', { name: 'expenses:categories.createCategory' }))

    // Fill in the category name (first textbox = name input)
    fireEvent.change(screen.getAllByRole('textbox')[0], {
      target: { value: 'Travel' },
    })

    // Select a GL account
    fireEvent.change(screen.getByLabelText('expenses:categories.form.glAccount'), {
      target: { value: 'acc-601' },
    })

    // Submit the form
    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => {
      expect(mockCreateMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ account_id: 'acc-601' }),
      )
    })
  })

  it('queries accounts with type=expense filter', () => {
    render(<ExpenseCategoryPage />)
    expect(mockUseAccounts).toHaveBeenCalledWith({ type: 'expense' })
  })
})
