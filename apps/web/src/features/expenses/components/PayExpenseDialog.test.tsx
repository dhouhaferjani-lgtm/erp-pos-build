import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { format } from 'date-fns'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Expense } from '../types'
import { formatCurrency } from '@/lib/format'
import { PayExpenseDialog } from './PayExpenseDialog'

const mocks = vi.hoisted(() => ({ mutate: vi.fn() }))

vi.mock('../hooks/useExpenses', () => ({
  usePayExpense: () => ({ mutate: mocks.mutate, isPending: false }),
}))

vi.mock('../../treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({
    data: [{ id: 'repo-1', name: 'Main Register' }],
    isLoading: false,
  }),
}))

vi.mock('../../treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({
    data: [{ id: 'method-1', name: 'Cash' }],
    isLoading: false,
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const expense = {
  id: 'expense-1',
  status: 'posted',
  type: 'expense',
  document_number: 'EXP-1',
  document_date: '2026-07-10',
  partner_id: null,
  partner: null,
  subtotal: '120.000',
  tax_amount: null,
  total: '120.000',
  currency: 'TND',
  notes: null,
  internal_notes: null,
  created_at: '2026-07-10T00:00:00Z',
  updated_at: '2026-07-10T00:00:00Z',
  metadata: null,
} satisfies Expense

describe('PayExpenseDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.mutate.mockImplementation((
      _request: unknown,
      options?: { onSuccess?: (paid: Expense) => void },
    ) => options?.onSuccess?.(expense))
  })

  it('shows the total read-only and populates repository and optional method fields', () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)

    expect(screen.getByText(formatCurrency(expense.total, { currency: expense.currency }))).toBeInTheDocument()
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument()
    expect(within(screen.getByLabelText(/expenses:pay\.repository/)).getByRole('option', { name: 'Main Register' })).toBeInTheDocument()
    const method = screen.getByLabelText('expenses:pay.method')
    expect(within(method).getByRole('option', { name: 'expenses:pay.noMethod' })).toHaveValue('')
    expect(within(method).getByRole('option', { name: 'Cash' })).toHaveValue('method-1')
    expect(screen.getByLabelText(/expenses:pay\.date/)).toHaveAttribute('type', 'date')
    expect(screen.getByLabelText(/expenses:pay\.date/)).toHaveValue(format(new Date(), 'yyyy-MM-dd'))
  })

  it('submits the exact contract without amount and closes after success', async () => {
    const onClose = vi.fn()
    const onSuccess = vi.fn()
    render(<PayExpenseDialog isOpen onClose={onClose} expense={expense} onSuccess={onSuccess} />)

    fireEvent.change(screen.getByLabelText(/expenses:pay\.repository/), { target: { value: 'repo-1' } })
    fireEvent.change(screen.getByLabelText('expenses:pay.method'), { target: { value: 'method-1' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.date/), { target: { value: '2026-07-12' } })
    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))

    await waitFor(() => {
      expect(mocks.mutate).toHaveBeenCalledWith({
        id: 'expense-1',
        data: {
          payment_repository_id: 'repo-1',
          payment_method_id: 'method-1',
          payment_date: '2026-07-12',
        },
      }, expect.objectContaining({ onSuccess: expect.any(Function) }))
    })
    expect(mocks.mutate.mock.calls[0]?.[0].data).not.toHaveProperty('amount')
    expect(onSuccess).toHaveBeenCalledWith(expense)
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('requires a repository before submitting', async () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)
    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))

    await waitFor(() => expect(screen.getByText('expenses:pay.repositoryRequired')).toBeInTheDocument())
    expect(mocks.mutate).not.toHaveBeenCalled()
  })
})
