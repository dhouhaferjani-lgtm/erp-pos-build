import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { DocumentLineVariantSelector } from '../DocumentLineVariantSelector'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key }),
}))

let mockVariants: unknown[] = []
let mockIsLoading = false
vi.mock('../../../catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: mockVariants, isLoading: mockIsLoading }),
}))

function variant(over: Record<string, unknown>) {
  return {
    id: 'v1',
    tenant_id: 't1',
    company_id: 'c1',
    product_id: 'p1',
    variant_code: 'RED-S',
    sku: 'SKU-RED-S',
    barcode: null,
    name_suffix: 'Red / S',
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: null,
    cost_override: null,
    image_url: null,
    ...over,
  }
}

describe('DocumentLineVariantSelector', () => {
  beforeEach(() => {
    mockVariants = []
    mockIsLoading = false
  })

  it('renders nothing when the product has no variants', () => {
    const { container } = render(
      <DocumentLineVariantSelector productId="p1" value={null} onChange={vi.fn()} />,
    )
    expect(container.querySelector('select')).toBeNull()
  })

  it('renders one option per active variant plus a placeholder', () => {
    mockVariants = [
      variant({ id: 'v1', name_suffix: 'Red / S', sku: 'SKU-RED-S' }),
      variant({ id: 'v2', name_suffix: 'Red / M', sku: 'SKU-RED-M' }),
      variant({ id: 'gone', name_suffix: 'Old', is_active: false }),
    ]
    render(<DocumentLineVariantSelector productId="p1" value={null} onChange={vi.fn()} />)

    const options = screen.getAllByRole('option')
    // placeholder + 2 active variants
    expect(options).toHaveLength(3)
    expect(screen.getByRole('option', { name: /Red \/ S/ })).toBeTruthy()
  })

  it('emits the variant id on change', () => {
    const onChange = vi.fn()
    mockVariants = [variant({ id: 'v2', name_suffix: 'Red / M', sku: 'SKU-RED-M' })]
    render(<DocumentLineVariantSelector productId="p1" value={null} onChange={onChange} />)

    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'v2' } })
    expect(onChange).toHaveBeenCalledWith('v2')
  })

  it('emits null when the placeholder is reselected', () => {
    const onChange = vi.fn()
    mockVariants = [variant({ id: 'v2', name_suffix: 'Red / M', sku: 'SKU-RED-M' })]
    render(<DocumentLineVariantSelector productId="p1" value="v2" onChange={onChange} />)

    fireEvent.change(screen.getByRole('combobox'), { target: { value: '' } })
    expect(onChange).toHaveBeenCalledWith(null)
  })
})
