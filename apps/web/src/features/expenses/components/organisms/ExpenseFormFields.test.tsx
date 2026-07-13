import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '@/lib/i18n'
import type { PartnerPickerValue } from '@/components/molecules/pickers'
import type { CreateExpenseDTO } from '../../types'
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
    onChange,
    partnerType,
  }: {
    onChange: (value: PartnerPickerValue | null) => void
    partnerType: string
  }) => (
    <button type="button" data-partner-type={partnerType} onClick={() => onChange(supplier)}>
      Choose supplier
    </button>
  ),
}))

function renderForm(onSave = vi.fn<(data: CreateExpenseDTO) => void>()) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <ExpenseFormFields onSave={onSave} />
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
    expect(picker).toHaveAttribute('data-partner-type', 'supplier')

    await user.click(picker)

    const vendorName = screen.getByRole('textbox', { name: 'Vendor Name' })
    expect(vendorName).toHaveValue('Papeterie Atlas')

    await user.clear(vendorName)
    await user.type(vendorName, 'Papeterie Atlas — Lac 1')
    expect(vendorName).toHaveValue('Papeterie Atlas — Lac 1')
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

  it('hides VAT receipt arithmetic for linked costs', async () => {
    const { user } = renderForm()

    expect(screen.getByRole('combobox', { name: 'VAT rate' })).toBeInTheDocument()
    await user.click(screen.getByRole('radio', { name: 'Cost linked to an operation' }))

    expect(screen.queryByRole('combobox', { name: 'VAT rate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('spinbutton', { name: 'VAT amount' })).not.toBeInTheDocument()
  })
})
