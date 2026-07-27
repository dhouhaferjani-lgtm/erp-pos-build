import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { format } from 'date-fns'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Expense } from '../types'
import { formatCurrency } from '@/lib/format'
import { PayExpenseDialog } from './PayExpenseDialog'

const mocks = vi.hoisted(() => ({ mutate: vi.fn(), useBanks: vi.fn() }))

vi.mock('../hooks/useExpenses', () => ({
  usePayExpense: () => ({ mutate: mocks.mutate, isPending: false }),
}))

vi.mock('../../treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({
    data: [
      { id: 'repo-1', name: 'Main Register', type: 'cash_register' },
      { id: 'repo-bank', name: 'Main Bank', type: 'bank_account' },
    ],
    isLoading: false,
  }),
}))

vi.mock('../../treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({
    data: [
      { id: 'method-1', name: 'Cash', instrument_kind: null },
      { id: 'method-other', name: 'Direct Debit', instrument_kind: 'other' },
      { id: 'method-cheque', name: 'Cheque', instrument_kind: 'cheque' },
      { id: 'method-effet', name: 'Effet', instrument_kind: 'effet' },
    ],
    isLoading: false,
  }),
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfigOptional: () => ({ config: { country_code: 'TN' } }),
}))

vi.mock('@/hooks/useBanks', () => ({ useBanks: mocks.useBanks }))

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
    mocks.useBanks.mockReturnValue({
      data: [{
        id: 'bank-1',
        country_code: 'TN',
        name: 'BIAT',
        short_name: 'BIAT',
        bic: 'BIATTNTT',
        rib_bank_code: '08',
        city: 'TUNIS',
        is_custom: false,
      }],
      isLoading: false,
      isError: false,
    })
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
    expect(within(method).getByRole('option', { name: 'Direct Debit' })).toHaveValue('method-other')
    expect(within(method).queryByRole('option', { name: 'Cheque' })).not.toBeInTheDocument()
    expect(within(method).queryByRole('option', { name: 'Effet' })).not.toBeInTheDocument()
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

  it('submits cheque settlement as a nested string-only instrument contract', async () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)

    fireEvent.click(screen.getByLabelText('expenses:pay.modes.instrument'))
    expect(within(screen.getByLabelText(/expenses:pay\.method/)).queryByRole('option', { name: 'Direct Debit' })).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.kind/), { target: { value: 'cheque' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.repository/), { target: { value: 'repo-bank' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.method/), { target: { value: 'method-cheque' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.reference/), { target: { value: 'CHK-2026-0042' } })
    const bankPicker = screen.getByRole('combobox', { name: 'expenses:pay.instrument.bank' })
    fireEvent.focus(bankPicker)
    fireEvent.click(screen.getByRole('option', { name: /BIAT/ }))
    fireEvent.change(screen.getByLabelText('expenses:pay.instrument.drawerName'), { target: { value: 'Vendor SARL' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.date/), { target: { value: '2026-07-18' } })
    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))

    await waitFor(() => {
      expect(mocks.mutate).toHaveBeenCalledWith({
        id: 'expense-1',
        data: {
          mode: 'instrument',
          payment_repository_id: 'repo-bank',
          payment_method_id: 'method-cheque',
          payment_date: '2026-07-18',
          instrument: {
            kind: 'cheque',
            reference: 'CHK-2026-0042',
            bank_id: 'bank-1',
            maturity_date: null,
            drawer_name: 'Vendor SARL',
          },
        },
      }, expect.objectContaining({ onSuccess: expect.any(Function) }))
    })
    expect(JSON.stringify(mocks.mutate.mock.calls[0]?.[0].data)).not.toMatch(/120\.000/)
  })

  it('requires a maturity date for an effet settlement', async () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)

    fireEvent.click(screen.getByLabelText('expenses:pay.modes.instrument'))
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.kind/), { target: { value: 'effet' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.repository/), { target: { value: 'repo-bank' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.method/), { target: { value: 'method-effet' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.reference/), { target: { value: 'EFF-2026-0042' } })
    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))

    await waitFor(() => {
      expect(screen.getByText('expenses:pay.instrument.maturityDateRequired')).toBeInTheDocument()
    })
    expect(mocks.mutate).not.toHaveBeenCalled()
  })

  it('clears an effet maturity date when switching back to cheque', async () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)

    fireEvent.click(screen.getByLabelText('expenses:pay.modes.instrument'))
    const kind = screen.getByLabelText(/expenses:pay\.instrument\.kind/)
    fireEvent.change(kind, { target: { value: 'effet' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.maturityDate/), { target: { value: '2026-09-30' } })
    fireEvent.change(kind, { target: { value: 'cheque' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.repository/), { target: { value: 'repo-bank' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.method/), { target: { value: 'method-cheque' } })
    fireEvent.change(screen.getByLabelText(/expenses:pay\.instrument\.reference/), { target: { value: 'CHK-AFTER-EFFET' } })
    fireEvent.click(screen.getByRole('button', { name: 'expenses:pay.submit' }))

    await waitFor(() => {
      expect(mocks.mutate).toHaveBeenCalledWith(expect.objectContaining({
        data: expect.objectContaining({
          instrument: expect.objectContaining({ maturity_date: null }),
        }),
      }), expect.any(Object))
    })
  })

  it('does not offer an unsaved fallback bank name', () => {
    render(<PayExpenseDialog isOpen onClose={vi.fn()} expense={expense} />)

    fireEvent.click(screen.getByLabelText('expenses:pay.modes.instrument'))
    fireEvent.focus(screen.getByRole('combobox', { name: 'expenses:pay.instrument.bank' }))

    expect(screen.queryByRole('button', { name: 'bank.notListed' })).not.toBeInTheDocument()
  })
})
