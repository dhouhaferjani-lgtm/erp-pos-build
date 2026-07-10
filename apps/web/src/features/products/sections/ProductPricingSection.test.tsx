import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'

import { makeProductSectionProduct, makeProductSectionViewData } from './__fixtures__/productSectionProduct'
import { ProductPricingSection } from './ProductPricingSection'
import type { ProductSectionFormData } from './types'
import { useProductPricingEditAdapter } from './useProductPricingEditAdapter'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => <div data-testid="tax-configuration" />,
}))

function EditHarness({ canViewCostPrices }: { canViewCostPrices: boolean }) {
  const form = useForm<ProductSectionFormData>({
    defaultValues: {
      purchase_price: '80.000',
      sale_price: '100.000',
      tax_rate: '20.00',
      tax_configuration_id: null,
      opening_qty: '',
    },
  })
  const pricing = useProductPricingEditAdapter({
    control: form.control,
    setValue: form.setValue,
    isOpeningLocked: true,
    productCostPrice: '80.000',
    canEnterOpening: false,
    moneyScale: 3,
  })

  return (
    <ProductPricingSection
      adapter={{
        mode: 'edit',
        canViewCostPrices,
        currency: 'EUR',
        locale: 'en-US',
        moneyScale: 3,
        form: {
          control: form.control,
          setValue: form.setValue,
          watch: form.watch,
        },
        isEditing: true,
        product: makeProductSectionProduct(),
        pricing,
      }}
    />
  )
}

function ViewHarness({ canViewCostPrices }: { canViewCostPrices: boolean }) {
  const { product, costPrices } = makeProductSectionViewData()

  return (
    <ProductPricingSection
      adapter={{
        mode: 'view',
        canViewCostPrices,
        costPrices: canViewCostPrices ? costPrices : null,
        currency: 'EUR',
        locale: 'en-US',
        moneyScale: 3,
        formatCurrency: (value) => value ?? '\u2014',
        formatPercent: (value) => value === null ? '\u2014' : `${value}%`,
        product,
      }}
    />
  )
}

describe('ProductPricingSection', () => {
  it('shows public HT/TTC/tax facts but hides cost and margin facts in view mode without permission', () => {
    const { container } = render(<ViewHarness canViewCostPrices={false} />)

    expect(container.querySelectorAll('#section-pricing')).toHaveLength(1)
    expect(screen.getByText('inventory:pricing.salePriceHt')).toBeInTheDocument()
    expect(screen.getByText('inventory:pricing.salePriceTtc')).toBeInTheDocument()
    expect(screen.queryByText('inventory:pricing.wac')).not.toBeInTheDocument()
    expect(screen.queryByText('inventory:pricing.margin')).not.toBeInTheDocument()
  })

  it('shows guarded WAC and margin facts in view mode with permission', () => {
    render(<ViewHarness canViewCostPrices />)

    expect(screen.getByText('inventory:pricing.wac')).toBeInTheDocument()
    expect(screen.getByText('inventory:pricing.margin')).toBeInTheDocument()
  })

  it('hides cost and margin controls in edit mode without permission', () => {
    render(<EditHarness canViewCostPrices={false} />)

    expect(screen.getByLabelText('inventory:products.priceHt')).toBeInTheDocument()
    expect(screen.getByLabelText('inventory:products.priceTtc')).toBeInTheDocument()
    expect(screen.queryByLabelText('inventory:products.costWac')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('inventory:products.marginPercent')).not.toBeInTheDocument()
  })

  it('shows guarded WAC and margin controls in edit mode with permission', () => {
    render(<EditHarness canViewCostPrices />)

    expect(screen.getByLabelText('inventory:products.costWac')).toBeInTheDocument()
    expect(screen.getByLabelText('inventory:products.marginPercent')).toBeInTheDocument()
  })

  it('preserves a literal focused margin draft in the rendered control', () => {
    render(<EditHarness canViewCostPrices />)
    const margin = screen.getByLabelText('inventory:products.marginPercent')

    fireEvent.focus(margin)
    fireEvent.change(margin, { target: { value: '50.00' } })

    expect(margin).toHaveValue(50)
    expect(margin).toHaveAttribute('value', '50.00')
  })
})
