import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '@/lib/i18n'
import type { PartnerPickerValue } from '@/components/molecules/pickers'
import type { CreateExpenseDTO, Expense, ExpenseMetadata } from '../../types'
import { ExpenseFormFields } from './ExpenseFormFields'

vi.mock('../../../treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({ data: [], isLoading: false, error: null }),
}))

vi.mock('../../../treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({ data: [], isLoading: false, error: null }),
}))

vi.mock('../../../../hooks/useCurrency', () => ({
  getDecimals: () => 3,
  useCurrency: () => ({ currency: 'TND' }),
}))

vi.mock('../../../../hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: () => ({
    data: [
      {
        id: 'tax-19',
        name: 'TVA',
        tax_type: 'PERCENTAGE',
        percentage_rate: '19.00',
        applies_to: 'LINE_ITEMS',
        is_active: true,
      },
    ],
    isLoading: false,
  }),
}))

vi.mock('../molecules/ExpenseCategorySelect', () => ({
  ExpenseCategorySelect: () => null,
}))

const supplier: PartnerPickerValue = {
  id: 'supplier-1',
  name: 'Papeterie Atlas',
  type: 'supplier',
}

vi.mock('@/components/molecules/pickers', () => ({
  PartnerPicker: ({
    value,
    onChange,
    partnerType,
  }: {
    value: PartnerPickerValue | string | null
    onChange: (value: PartnerPickerValue | null) => void
    partnerType: string
  }) => (
    <div data-partner-type={partnerType}>
      <span data-testid="selected-supplier">
        {typeof value === 'string' ? value : value?.id ?? 'none'}
      </span>
      <button type="button" onClick={() => { onChange(supplier) }}>
        Choose supplier
      </button>
      <button type="button" onClick={() => { onChange(null) }}>
        Clear supplier
      </button>
    </div>
  ),
}))

const editExpenseMetadata: ExpenseMetadata = {
  vendor_name: 'Papeterie Atlas receipt desk',
  receipt_number: null,
  payment_date: null,
  is_paid: false,
  expense_category_id: null,
  payment_method_id: null,
  payment_repository_id: null,
  expense_kind: 'generic',
  vat_rate: '19.00',
  vat_deductible_percent: '100.00',
}

const editExpense: Expense = {
  id: 'expense-1',
  type: 'expense',
  status: 'draft',
  document_number: 'EXP-0001',
  document_date: '2026-07-13',
  partner_id: supplier.id,
  partner: { id: supplier.id, name: supplier.name },
  subtotal: '100.000',
  tax_amount: '19.000',
  total: '119.000',
  currency: 'TND',
  notes: null,
  internal_notes: null,
  created_at: '2026-07-13T00:00:00Z',
  updated_at: '2026-07-13T00:00:00Z',
  metadata: editExpenseMetadata,
}

function renderForm(
  onSave = vi.fn<(data: CreateExpenseDTO) => void>(),
  expense?: Expense,
) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <ExpenseFormFields
        onSave={onSave}
        {...(expense !== undefined ? { expense } : {})}
      />
    </QueryClientProvider>,
  )

  return { onSave, user: userEvent.setup() }
}

beforeEach(async () => {
  await i18n.changeLanguage('en')
})

describe('ExpenseFormFields supplier and VAT capture', () => {
  it('selects a supplier while keeping the vendor snapshot editable', async () => {
    const { user } = renderForm()

    const picker = screen.getByRole('button', { name: 'Choose supplier' })
    expect(picker.parentElement).toHaveAttribute('data-partner-type', 'supplier')

    await user.click(picker)

    const vendorName = screen.getByRole('textbox', { name: 'Vendor Name' })
    expect(vendorName).toHaveValue('Papeterie Atlas')

    await user.clear(vendorName)
    await user.type(vendorName, 'Papeterie Atlas — Lac 1')
    expect(vendorName).toHaveValue('Papeterie Atlas — Lac 1')
  })

  it('synchronizes the selected supplier in edit mode while keeping its snapshot editable', async () => {
    const onSave = vi.fn<(data: CreateExpenseDTO) => void>()
    const { user } = renderForm(onSave, editExpense)

    expect(screen.getByTestId('selected-supplier')).toHaveTextContent('supplier-1')
    const vendorName = screen.getByRole('textbox', { name: 'Vendor Name' })
    expect(vendorName).toHaveValue('Papeterie Atlas receipt desk')

    await user.clear(vendorName)
    await user.type(vendorName, 'Papeterie Atlas corrected receipt')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => { expect(onSave).toHaveBeenCalledTimes(1) })
    expect(onSave.mock.calls[0]?.[0]).toEqual(expect.objectContaining({
      partner_id: 'supplier-1',
      vendor_name: 'Papeterie Atlas corrected receipt',
    }))
  })

  it('suggests inclusive VAT with decimal strings and submits corrected receipt arithmetic', async () => {
    const { onSave, user } = renderForm()

    await user.click(screen.getByRole('button', { name: 'Choose supplier' }))
    fireEvent.change(screen.getByRole('spinbutton', { name: /^Amount/ }), {
      target: { value: '119.000' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'VAT rate' }), {
      target: { value: '19.00' },
    })

    const vatAmount = screen.getByRole('spinbutton', { name: 'VAT amount' })
    const deductible = screen.getByRole('spinbutton', { name: 'Deductible VAT' })
    expect(vatAmount).toHaveValue(19)
    expect(deductible).toHaveValue(100)
    expect(deductible).toHaveAttribute('step', '0.01')

    fireEvent.change(vatAmount, { target: { value: '18.999' } })
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => { expect(onSave).toHaveBeenCalledTimes(1) })
    const payload = onSave.mock.calls[0]?.[0]
    expect(payload).toEqual(expect.objectContaining({
      partner_id: 'supplier-1',
      total: '119.000',
      vat_amount: '18.999',
      vat_rate: '19.00',
      vat_deductible_percent: '100',
      vendor_name: 'Papeterie Atlas',
    }))
    expect(typeof payload?.total).toBe('string')
    expect(typeof payload?.vat_amount).toBe('string')
    expect(typeof payload?.vat_rate).toBe('string')
    expect(typeof payload?.vat_deductible_percent).toBe('string')
  })

  it('clears VAT receipt arithmetic before submitting a linked cost', async () => {
    const onSave = vi.fn<(data: CreateExpenseDTO) => void>()
    const { user } = renderForm(onSave)

    fireEvent.change(screen.getByRole('spinbutton', { name: /^Amount/ }), {
      target: { value: '119.000' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'VAT rate' }), {
      target: { value: '19.00' },
    })
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Deductible VAT' }), {
      target: { value: '80.00' },
    })

    await user.click(screen.getByRole('radio', { name: 'Cost linked to an operation' }))

    expect(screen.queryByRole('combobox', { name: 'VAT rate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('spinbutton', { name: 'VAT amount' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => { expect(onSave).toHaveBeenCalledTimes(1) })
    const payload = onSave.mock.calls[0]?.[0]
    expect(payload?.vat_amount).toBeUndefined()
    expect(payload?.vat_rate).toBeUndefined()
    expect(payload?.vat_deductible_percent).toBeUndefined()
  })

  it('keeps a historical VAT rate visible and submittable in edit mode', async () => {
    const onSave = vi.fn<(data: CreateExpenseDTO) => void>()
    const historicalExpense: Expense = {
      ...editExpense,
      tax_amount: '7.500',
      subtotal: '92.500',
      total: '100.000',
      metadata: {
        ...editExpenseMetadata,
        vat_rate: '7.50',
      },
    }
    const { user } = renderForm(onSave, historicalExpense)

    const rate = screen.getByRole('combobox', { name: 'VAT rate' })
    expect(rate).toHaveValue('7.50')
    expect(screen.getByRole('option', { name: /Historical rate.*7[.,]50%/ })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => { expect(onSave).toHaveBeenCalledTimes(1) })
    expect(onSave.mock.calls[0]?.[0]?.vat_rate).toBe('7.50')
  })
})
