import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { TaxConfigurationField } from './TaxConfigurationField'

vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: vi.fn().mockReturnValue({
    data: [
      {
        id: 'tax-1',
        name: 'TVA 19%',
        tax_type: 'PERCENTAGE',
        percentage_rate: '19.00',
        fixed_amount: null,
        applies_to: 'LINE_ITEMS',
        applicable_document_types: [],
        is_active: true,
        is_default: true,
        country_code: 'TN',
        code: 'TVA19',
        sequence_order: 1,
        stacks_on: 'BASE_AMOUNT',
        is_stamp_duty: false,
        is_recoverable: true,
        created_at: '',
        updated_at: '',
      },
    ],
    isLoading: false,
    isError: false,
  }),
}))

vi.mock('../../organisms/TaxConfigFormModal', () => ({
  TaxConfigFormModal: () => null,
}))

describe('TaxConfigurationField', () => {
  it('renders with label', () => {
    render(<TaxConfigurationField label="Tax Rate" value={null} onChange={vi.fn()} />)
    expect(screen.getByText('Tax Rate')).toBeInTheDocument()
  })

  it('shows required indicator', () => {
    render(<TaxConfigurationField label="Tax Rate" required value={null} onChange={vi.fn()} />)
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('shows error message', () => {
    render(<TaxConfigurationField label="Tax Rate" error="Tax is required" value={null} onChange={vi.fn()} />)
    expect(screen.getByText('Tax is required')).toBeInTheDocument()
  })

  it('renders the select dropdown', () => {
    render(<TaxConfigurationField label="Tax Rate" value={null} onChange={vi.fn()} />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })
})
