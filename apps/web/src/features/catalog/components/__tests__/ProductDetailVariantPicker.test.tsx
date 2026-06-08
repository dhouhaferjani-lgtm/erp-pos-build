import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ProductDetailVariantPicker } from '../ProductDetailVariantPicker'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key }),
}))

let mockVariants: unknown[] = []
let mockIsLoading = false
vi.mock('../../hooks/useVariants', () => ({
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

describe('ProductDetailVariantPicker', () => {
  beforeEach(() => {
    mockVariants = []
    mockIsLoading = false
  })

  it('renders nothing when the product has no variants', () => {
    const { container } = render(
      <ProductDetailVariantPicker productId="p1" value={null} onChange={vi.fn()} />,
    )
    expect(container.querySelector('[role="radiogroup"]')).toBeNull()
  })

  it('renders one radio per active variant', () => {
    mockVariants = [
      variant({ id: 'v1', name_suffix: 'Red / S' }),
      variant({ id: 'v2', name_suffix: 'Red / M' }),
      variant({ id: 'gone', name_suffix: 'Discontinued', is_active: false }),
    ]
    render(<ProductDetailVariantPicker productId="p1" value={null} onChange={vi.fn()} />)

    expect(screen.getAllByRole('radio')).toHaveLength(2)
    expect(screen.getByText('Red / S')).toBeTruthy()
    expect(screen.getByText('Red / M')).toBeTruthy()
  })

  it('marks the selected variant as checked', () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    render(<ProductDetailVariantPicker productId="p1" value="v1" onChange={vi.fn()} />)
    expect(screen.getByRole('radio', { name: 'Red / S' }).getAttribute('aria-checked')).toBe('true')
  })

  it('emits the variant id when a pill is clicked', () => {
    const onChange = vi.fn()
    mockVariants = [variant({ id: 'v2', name_suffix: 'Red / M' })]
    render(<ProductDetailVariantPicker productId="p1" value={null} onChange={onChange} />)

    fireEvent.click(screen.getByRole('radio', { name: 'Red / M' }))
    expect(onChange).toHaveBeenCalledWith('v2')
  })
})
