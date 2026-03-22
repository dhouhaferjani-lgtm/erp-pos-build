import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { TaxConfigurationSelect } from './TaxConfigurationSelect'

vi.mock('../../../hooks/useTaxConfigurations', () => ({
  useTaxConfigurations: vi.fn(),
}))

vi.mock('../../organisms/TaxConfigFormModal', () => ({
  TaxConfigFormModal: () => null,
}))

import { useTaxConfigurations } from '../../../hooks/useTaxConfigurations'

const mockConfigs = [
  {
    id: 'tax-1', name: 'TVA 19%', tax_type: 'PERCENTAGE' as const,
    percentage_rate: '19.00', fixed_amount: null, applies_to: 'LINE_ITEMS' as const,
    applicable_document_types: [], is_active: true, is_default: true,
    country_code: 'TN', code: 'TVA19', sequence_order: 1,
    stacks_on: 'BASE_AMOUNT' as const, is_stamp_duty: false, is_recoverable: true,
    created_at: '', updated_at: '',
  },
  {
    id: 'tax-2', name: 'TVA 7%', tax_type: 'PERCENTAGE' as const,
    percentage_rate: '7.00', fixed_amount: null, applies_to: 'LINE_ITEMS' as const,
    applicable_document_types: ['SALES_INVOICE'], is_active: true, is_default: false,
    country_code: 'TN', code: 'TVA7', sequence_order: 2,
    stacks_on: 'BASE_AMOUNT' as const, is_stamp_duty: false, is_recoverable: true,
    created_at: '', updated_at: '',
  },
  {
    id: 'tax-3', name: 'Stamp Duty', tax_type: 'FIXED_AMOUNT' as const,
    percentage_rate: null, fixed_amount: '1.000', applies_to: 'DOCUMENT_TOTAL' as const,
    applicable_document_types: ['SALES_INVOICE'], is_active: true, is_default: false,
    country_code: 'TN', code: 'STAMP', sequence_order: 3,
    stacks_on: 'BASE_AMOUNT' as const, is_stamp_duty: true, is_recoverable: false,
    created_at: '', updated_at: '',
  },
]

const mockUseTaxConfigurations = useTaxConfigurations as ReturnType<typeof vi.fn>

function setup(props: Partial<React.ComponentProps<typeof TaxConfigurationSelect>> = {}) {
  const defaultProps = { value: null, onChange: vi.fn(), ...props }
  return { ...render(<TaxConfigurationSelect {...defaultProps} />), onChange: defaultProps.onChange }
}

describe('TaxConfigurationSelect', () => {
  beforeEach(() => {
    mockUseTaxConfigurations.mockReturnValue({ data: mockConfigs, isLoading: false, isError: false })
  })

  it('renders a select with tax configurations', () => {
    setup()
    expect(screen.getByRole('combobox')).toBeInTheDocument()
    expect(screen.getByText('TVA 19% (19.00%)')).toBeInTheDocument()
    expect(screen.getByText('TVA 7% (7.00%)')).toBeInTheDocument()
    expect(screen.getByText('Stamp Duty (1.000)')).toBeInTheDocument()
  })

  it('filters OUT configs that do not match documentType', () => {
    setup({ documentType: 'QUOTE' })
    expect(screen.getByText('TVA 19% (19.00%)')).toBeInTheDocument()
    expect(screen.queryByText('TVA 7% (7.00%)')).not.toBeInTheDocument()
  })

  it('calls onChange with config ID and tax rate on selection', () => {
    const { onChange } = setup()
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'tax-1' } })
    expect(onChange).toHaveBeenCalledWith('tax-1', '19.00')
  })

  it('calls onChange with fixed amount for FIXED_AMOUNT type', () => {
    const { onChange } = setup()
    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'tax-3' } })
    expect(onChange).toHaveBeenCalledWith('tax-3', '1.000')
  })

  it('pre-selects the value prop', () => {
    setup({ value: 'tax-2' })
    expect((screen.getByRole('combobox') as HTMLSelectElement).value).toBe('tax-2')
  })

  it('shows placeholder when no value selected', () => {
    setup({ value: null })
    expect(screen.getByText(/select tax/i)).toBeInTheDocument()
  })

  it('shows "Add new tax" option', () => {
    setup()
    expect(screen.getByText(/add new tax/i)).toBeInTheDocument()
  })

  it('shows loading state while fetching', () => {
    mockUseTaxConfigurations.mockReturnValue({ data: undefined, isLoading: true, isError: false })
    setup()
    expect((screen.getByRole('combobox') as HTMLSelectElement).disabled).toBe(true)
  })

  it('handles empty configurations list', () => {
    mockUseTaxConfigurations.mockReturnValue({ data: [], isLoading: false, isError: false })
    setup()
    expect(screen.getByText(/add new tax/i)).toBeInTheDocument()
  })

  it('falls back to numeric input on error', () => {
    mockUseTaxConfigurations.mockReturnValue({ data: undefined, isLoading: false, isError: true })
    setup()
    expect(screen.getByRole('spinbutton')).toBeInTheDocument()
  })
})
