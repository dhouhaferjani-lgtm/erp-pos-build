import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'

import { makeProductSectionViewData } from './__fixtures__/productSectionProduct'
import { ProductInventorySection } from './ProductInventorySection'
import type { ProductSectionFormData } from './types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/features/inventory/components/ProductStockLevels', () => ({
  ProductStockLevels: ({ canViewCostPrices, embedded }: { canViewCostPrices: boolean; embedded: boolean }) => (
    <div
      data-testid="stock-levels"
      data-can-view-cost={String(canViewCostPrices)}
      data-embedded={String(embedded)}
    />
  ),
}))

function EditHarness() {
  const form = useForm<ProductSectionFormData>({
    defaultValues: {
      purchase_price: '15.000',
      opening_qty: '',
      opening_unit_cost: '',
      units_per_pack: null,
      shelf_location: '',
      reorder_point: '',
      reorder_quantity: '',
      requires_batch_tracking: false,
      default_shelf_life_days: null,
    },
  })

  return (
    <>
      <ProductInventorySection
        adapter={{
        mode: 'edit',
        form: {
          control: form.control,
          register: form.register,
          watch: form.watch,
          setValue: form.setValue,
          errors: form.formState.errors,
        },
        inventory: {
          reorderDecimals: 4,
          showBatchTracking: true,
          showOpeningSection: true,
          canEnterOpening: true,
          isOpeningLocked: false,
          productStockQuantity: null,
          canResetOpening: false,
          showResetConfirm: false,
          isResettingOpening: false,
          requestOpeningReset: vi.fn(),
          cancelOpeningReset: vi.fn(),
          resetOpening: vi.fn(),
        },
        }}
      />
      <output data-testid="opening-unit-cost-value">{form.watch('opening_unit_cost')}</output>
    </>
  )
}

describe('ProductInventorySection', () => {
  it('renders the canonical edit fields and preserves opening-cost coupling', () => {
    render(<EditHarness />)

    expect(screen.getByLabelText('inventory:products.unitsPerPack')).toBeInTheDocument()
    expect(screen.getByLabelText('inventory:products.shelfLocation')).toBeInTheDocument()
    expect(screen.getByLabelText('inventory:products.reorderPoint')).toBeInTheDocument()
    expect(screen.getByLabelText('inventory:products.reorderQuantity')).toBeInTheDocument()

    fireEvent.change(screen.getByTestId('opening-qty-input'), { target: { value: '3.0000' } })
    expect(screen.getByTestId('opening-unit-cost-value')).toHaveTextContent('15.000')

    fireEvent.click(screen.getByRole('switch', { name: 'inventory:products.requiresBatchTracking' }))
    expect(screen.getByLabelText('inventory:products.defaultShelfLifeDays')).toBeInTheDocument()
  })

  it('embeds read-only stock levels and forwards the cost permission gate', () => {
    const { product, costPrices } = makeProductSectionViewData()
    render(
      <ProductInventorySection
        adapter={{
          mode: 'view',
          canViewCostPrices: false,
          costPrices: null,
          currency: 'EUR',
          locale: 'en-US',
          product,
        }}
      />,
    )

    expect(screen.getByTestId('stock-levels')).toHaveAttribute('data-can-view-cost', 'false')
    expect(screen.getByTestId('stock-levels')).toHaveAttribute('data-embedded', 'true')
    expect(screen.queryByText(costPrices.costPrice ?? '')).not.toBeInTheDocument()
  })
})
