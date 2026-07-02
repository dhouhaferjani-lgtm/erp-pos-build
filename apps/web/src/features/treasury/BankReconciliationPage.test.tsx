import { beforeEach, describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { BankReconciliationPage } from './BankReconciliationPage'
import type { BankReconciliation } from '@/types/treasury'

// i18n: echo the key, or the string default/options when provided.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// Router: list view (no `id` search param), capture setSearchParams.
const mockSetSearchParams = vi.fn()
const mockSearchParams = new URLSearchParams('')
vi.mock('react-router-dom', () => ({
  useSearchParams: () => [mockSearchParams, mockSetSearchParams],
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

function makeReconciliation(overrides: Partial<BankReconciliation>): BankReconciliation {
  return {
    id: 'rec-1',
    repository_id: 'repo-1',
    repository_name: 'Main Bank Account',
    statement_date: '2026-06-14',
    opening_balance: '100.000',
    closing_balance: '200.000',
    statement_balance: '200.000',
    difference: '0.000',
    status: 'draft',
    created_by: null,
    completed_by: null,
    completed_at: null,
    notes: null,
    created_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

const reconciliationsReturn: {
  data: BankReconciliation[] | undefined
  isLoading: boolean
  error: unknown
} = {
  data: [
    makeReconciliation({ id: 'rec-1', repository_name: 'Main Bank Account', status: 'draft' }),
    makeReconciliation({ id: 'rec-2', repository_name: 'Savings Account', status: 'completed', difference: '12.500' }),
  ],
  isLoading: false,
  error: null,
}

const mockStartMutate = vi.fn()

vi.mock('./hooks/useReconciliation', () => ({
  useReconciliations: () => reconciliationsReturn,
  useReconciliation: () => ({ data: undefined, isLoading: false, error: null }),
  useReconciliationSummary: () => ({ data: undefined }),
  usePaymentRepositories: () => ({
    data: [{ id: 'repo-1', name: 'Main Bank Account', balance: '25000.000', currency: 'TND' }],
    isLoading: false,
  }),
  useStartReconciliation: () => ({ mutate: mockStartMutate, isPending: false }),
  useMatchItem: () => ({ mutate: vi.fn(), isPending: false }),
  useUnmatchItem: () => ({ mutate: vi.fn(), isPending: false }),
  useCompleteReconciliation: () => ({ mutate: vi.fn(), isPending: false }),
  useCancelReconciliation: () => ({ mutate: vi.fn(), isPending: false }),
}))

describe('BankReconciliationPage (canonical list)', () => {
  beforeEach(() => {
    mockStartMutate.mockClear()
    mockSetSearchParams.mockClear()
    mockSearchParams.delete('id')
  })

  it('renders exactly one h1 page title', () => {
    render(<BankReconciliationPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a row per reconciliation', () => {
    render(<BankReconciliationPage />)
    expect(screen.getByText('Main Bank Account')).toBeInTheDocument()
    expect(screen.getByText('Savings Account')).toBeInTheDocument()
  })

  it('renders status through StatusBadge (rounded-full pill)', () => {
    render(<BankReconciliationPage />)
    const badge = screen.getByText('reconciliation.status.draft')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('right-aligns money columns with tabular-nums', () => {
    const { container } = render(<BankReconciliationPage />)
    const tabularCells = container.querySelectorAll('.tabular-nums')
    expect(tabularCells.length).toBeGreaterThan(0)
  })

  it('links back to the finance hub', () => {
    render(<BankReconciliationPage />)
    expect(screen.getByRole('link', { name: 'back' })).toHaveAttribute('href', '/finance')
  })

  it('submits an explicit opening balance when starting a reconciliation', () => {
    render(<BankReconciliationPage />)

    fireEvent.click(screen.getAllByRole('button', { name: 'reconciliation.startNew' })[0])

    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/reconciliation\.repository/), {
      target: { value: 'repo-1' },
    })
    fireEvent.change(within(dialog).getByLabelText(/reconciliation\.statementDate/), {
      target: { value: '2026-07-03' },
    })
    fireEvent.change(within(dialog).getByLabelText(/treasury:reconciliation\.openingBalance/), {
      target: { value: '25000.000' },
    })
    fireEvent.change(within(dialog).getByLabelText(/reconciliation\.statementBalance/), {
      target: { value: '25500.000' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'reconciliation.startNew' }))

    expect(mockStartMutate).toHaveBeenCalledWith(
      {
        repository_id: 'repo-1',
        statement_date: '2026-07-03',
        opening_balance: '25000.000',
        statement_balance: '25500.000',
      },
      expect.any(Object)
    )
  })
})
