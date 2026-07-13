import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { RecurringExpensesPage } from './RecurringExpensesPage'
import { formatCurrency, formatDate } from '@/lib/format'
import type { ExpenseRecurrenceTemplate } from '../types'

const mockCreate = vi.hoisted(() => vi.fn())
const mockDelete = vi.hoisted(() => vi.fn())
const mockPause = vi.hoisted(() => vi.fn())
const mockResume = vi.hoisted(() => vi.fn())
const mockUpdate = vi.hoisted(() => vi.fn())
const mockHasPermission = vi.hoisted(() => vi.fn((_permission: string) => true))

const template: ExpenseRecurrenceTemplate = {
  id: 'rec-1',
  name: 'Tunis office rent',
  expense_category_id: 'category-1',
  partner_id: 'supplier-1',
  payment_method_id: null,
  payment_repository_id: null,
  vendor_name: 'Tunis Properties',
  amount: '1250.000',
  vat_rate: '19.00',
  vat_deductible_percent: '80.00',
  vat_amount: '199.580',
  notes: 'Main office',
  frequency: 'monthly',
  start_date: '2026-07-31',
  end_date: null,
  lead_days: 3,
  status: 'active',
  next_due_date: '2026-08-31',
  created_by: 'user-1',
}

vi.mock('../hooks/useExpenseRecurrences', () => ({
  useExpenseRecurrences: () => ({ data: [template], isLoading: false, isError: false }),
  useCreateExpenseRecurrence: () => ({ mutateAsync: mockCreate, isPending: false }),
  useUpdateExpenseRecurrence: () => ({ mutateAsync: mockUpdate, isPending: false }),
  useDeleteExpenseRecurrence: () => ({ mutateAsync: mockDelete, isPending: false }),
  usePauseExpenseRecurrence: () => ({ mutateAsync: mockPause, isPending: false }),
  useResumeExpenseRecurrence: () => ({ mutateAsync: mockResume, isPending: false }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: mockHasPermission,
  }),
}))

vi.mock('@/hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useCurrency')>()
  return { ...actual, useCurrency: () => ({ currency: 'TND' }) }
})

vi.mock('../hooks/useExpenseCategories', () => ({
  useExpenseCategories: () => ({ data: [{ id: 'category-1', name: 'Rent' }] }),
}))

vi.mock('@/features/treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({ data: [{ id: 'method-1', name: 'Transfer' }] }),
}))

vi.mock('@/features/treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({ data: [{ id: 'repo-1', name: 'Main bank' }] }),
}))

vi.mock('@/hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: () => ({
    data: [{
      id: 'tax-1',
      is_active: true,
      tax_type: 'PERCENTAGE',
      applies_to: 'LINE_ITEMS',
      percentage_rate: '19.00',
    }],
  }),
}))

vi.mock('@/components/molecules/pickers', () => ({
  PartnerPicker: ({ label, onChange }: {
    label: string
    onChange: (value: { id: string; name: string }) => void
  }) => (
    <button type="button" onClick={() => {
      onChange({ id: 'supplier-2', name: 'Chosen Supplier' })
    }}>
      {label}
    </button>
  ),
}))

beforeEach(() => {
  vi.clearAllMocks()
  template.vat_rate = '19.00'
  template.vat_amount = '199.580'
  template.status = 'active'
  mockHasPermission.mockReturnValue(true)
  mockCreate.mockResolvedValue(template)
  mockUpdate.mockResolvedValue(template)
  mockDelete.mockResolvedValue(undefined)
  mockPause.mockResolvedValue({ ...template, status: 'paused' })
  mockResume.mockResolvedValue(template)
})

describe('RecurringExpensesPage', () => {
  it('makes cadence and next due the list hierarchy while preserving money precision', () => {
    render(<RecurringExpensesPage />)

    const row = screen.getByRole('listitem', { name: /Tunis office rent/i })
    expect(within(row).getByText('Tunis office rent')).toBeInTheDocument()
    expect(within(row).getByText('Monthly')).toHaveClass('rounded-full')
    expect(within(row).getByText(formatDate('2026-08-31'))).toBeInTheDocument()
    expect(row.textContent).toContain(formatCurrency('1250.000', { currency: 'TND' }))
    expect(within(row).getByText('Active')).toBeInTheDocument()
  })

  it('gates create, edit, pause, and delete actions by exact recurrence permissions', () => {
    mockHasPermission.mockImplementation((permission: string) => permission === 'expense-recurrences.view')

    render(<RecurringExpensesPage />)

    expect(screen.queryByRole('button', { name: /New recurring expense/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit Tunis office rent/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Pause Tunis office rent/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Delete Tunis office rent/i })).not.toBeInTheDocument()
  })

  it('pauses an active schedule from the list', async () => {
    const user = userEvent.setup()
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /Pause Tunis office rent/i }))

    expect(mockPause).toHaveBeenCalledWith('rec-1')
  })

  it('reuses supplier and VAT semantics in the create form and submits strings', async () => {
    const user = userEvent.setup()
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /New recurring expense/i }))
    const dialog = screen.getByRole('dialog', { name: /New recurring expense/i })

    expect(within(dialog).getByRole('button', { name: 'Supplier' })).toBeInTheDocument()
    expect(within(dialog).getByRole('spinbutton', { name: 'Amount' })).toHaveAttribute('step', '0.001')
    expect(within(dialog).getByRole('spinbutton', { name: 'VAT rate' })).toHaveAttribute('step', '0.01')
    expect(within(dialog).getByRole('spinbutton', { name: 'Deductible VAT' })).toHaveAttribute('step', '0.01')

    await user.type(within(dialog).getByRole('textbox', { name: /Schedule name/ }), 'Monthly hosting')
    fireEvent.change(within(dialog).getByRole('spinbutton', { name: 'Amount' }), {
      target: { value: '119.000' },
    })
    const vatRate = within(dialog).getByRole('spinbutton', { name: 'VAT rate' })
    const vatAmount = within(dialog).getByRole('spinbutton', { name: 'VAT amount' })
    fireEvent.change(vatRate, { target: { value: '19.00' } })
    expect(vatAmount).toHaveValue(19)

    fireEvent.change(vatRate, { target: { value: '' } })
    expect(vatAmount).toHaveValue(null)

    fireEvent.change(vatRate, { target: { value: '19.00' } })
    await user.click(within(dialog).getByRole('button', { name: 'Supplier' }))
    await user.click(within(dialog).getByRole('button', { name: 'Save schedule' }))

    expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Monthly hosting',
      amount: '119.000',
      partner_id: 'supplier-2',
      vendor_name: 'Chosen Supplier',
      vat_rate: '19.00',
      vat_amount: '19.000',
      vat_deductible_percent: '100',
      frequency: 'monthly',
    }))
  })

  it('keeps a historical VAT rate representable when editing a schedule', async () => {
    template.vat_rate = '7.50'
    template.vat_amount = '87.209'
    const user = userEvent.setup()
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /Edit Tunis office rent/i }))

    expect(screen.getByRole('spinbutton', { name: 'VAT rate' })).toHaveValue(7.5)
  })

  it('contains a rejected pause mutation after the hook reports the error', async () => {
    const user = userEvent.setup()
    mockPause.mockRejectedValueOnce(new Error('pause failed'))
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /Pause Tunis office rent/i }))

    await waitFor(() => { expect(mockPause).toHaveBeenCalledWith('rec-1') })
  })

  it('contains a rejected resume mutation after the hook reports the error', async () => {
    const user = userEvent.setup()
    template.status = 'paused'
    mockResume.mockRejectedValueOnce(new Error('resume failed'))
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /Resume Tunis office rent/i }))

    await waitFor(() => { expect(mockResume).toHaveBeenCalledWith('rec-1') })
  })

  it('contains a rejected delete mutation after the hook reports the error', async () => {
    const user = userEvent.setup()
    vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    mockDelete.mockRejectedValueOnce(new Error('delete failed'))
    render(<RecurringExpensesPage />)

    await user.click(screen.getByRole('button', { name: /Delete Tunis office rent/i }))

    await waitFor(() => { expect(mockDelete).toHaveBeenCalledWith('rec-1') })
  })
})
