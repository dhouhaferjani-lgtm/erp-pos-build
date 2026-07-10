import { render } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'

import { makeProductSectionViewData } from './__fixtures__/productSectionProduct'
import { ProductSectionStack } from './ProductSectionStack'
import type { ProductPricingFieldController, ProductSectionFormData } from './types'

vi.mock('./ProductHeroSection', () => ({ ProductHeroSection: () => <section id="section-hero" /> }))
vi.mock('./ProductGeneralSection', () => ({ ProductGeneralSection: () => <section id="section-general" /> }))
vi.mock('./ProductPricingSection', () => ({ ProductPricingSection: () => <section id="section-pricing" /> }))
vi.mock('./ProductInventorySection', () => ({ ProductInventorySection: () => <section id="section-inventory" /> }))
vi.mock('./ProductSuppliersSection', () => ({ ProductSuppliersSection: () => <section id="section-suppliers" /> }))
vi.mock('./ProductMediaSection', () => ({ ProductMediaSection: () => <section id="section-media" /> }))

const inertPricingField: ProductPricingFieldController = {
  value: '',
  onFocus: vi.fn(),
  onChange: vi.fn(),
  onBlur: vi.fn(),
}

function ViewStack() {
  const { product, costPrices } = makeProductSectionViewData()

  return (
    <ProductSectionStack
      mode="view"
      adapters={{
        hero: { mode: 'view', product },
        general: { mode: 'view', product },
        pricing: {
          mode: 'view',
          product,
          canViewCostPrices: true,
          costPrices,
          currency: 'EUR',
          locale: 'en-US',
          moneyScale: 2,
          formatCurrency: (value) => value ?? '—',
          formatPercent: (value) => value ?? '—',
        },
        inventory: {
          mode: 'view',
          product,
          canViewCostPrices: true,
          costPrices,
          currency: 'EUR',
          locale: 'en-US',
        },
        suppliers: { mode: 'view' },
        media: { mode: 'view', product },
      }}
      automotive={<div>automotive</div>}
    />
  )
}

function EditStack({ withExtensions = false }: { withExtensions?: boolean }) {
  const form = useForm<ProductSectionFormData>({
    defaultValues: {
      name: '',
      barcode: '',
      purchase_price: '',
      sale_price: '',
      tax_rate: '0',
      tax_configuration_id: null,
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
  const formAdapter = {
    control: form.control,
    register: form.register,
    watch: form.watch,
    setValue: form.setValue,
    errors: form.formState.errors,
  }
  const media = { bufferedImages: [], onBufferedImagesChange: vi.fn() }

  return (
    <ProductSectionStack
      mode="edit"
      adapters={{
        hero: {
          mode: 'edit',
          name: '',
          barcode: '',
          productId: null,
          primaryImageUrl: null,
          media,
          hero: {
            enrichmentState: 'never-submitted',
            chips: [],
            onBarcodeChange: vi.fn(),
            onNameChange: vi.fn(),
            onProductData: vi.fn(),
            onLookupStateChange: vi.fn(),
            onManualRefresh: vi.fn(),
          },
        },
        general: {
          mode: 'edit',
          form: formAdapter,
          general: { prefilledFields: new Set(), clearPrefilledField: vi.fn() },
        },
        pricing: {
          mode: 'edit',
          canViewCostPrices: true,
          currency: 'EUR',
          locale: 'en-US',
          moneyScale: 2,
          form: formAdapter,
          isEditing: false,
          product: null,
          pricing: {
            costBasis: '',
            cost: '',
            margin: inertPricingField,
            priceHt: inertPricingField,
            priceTtc: inertPricingField,
            commitCost: vi.fn(),
          },
        },
        inventory: {
          mode: 'edit',
          form: formAdapter,
          inventory: {
            reorderDecimals: 4,
            showBatchTracking: false,
            showOpeningSection: true,
            canEnterOpening: true,
            isOpeningLocked: false,
            productStockQuantity: null,
            canResetOpening: false,
            showResetConfirm: false,
            isResettingOpening: false,
            requestOpeningReset: vi.fn(),
            cancelOpeningReset: vi.fn(),
            resetOpening: vi.fn(async () => undefined),
          },
        },
        suppliers: { mode: 'edit' },
        media: { mode: 'edit', isEditing: false, productId: null, media },
      }}
      {...(withExtensions
        ? {
            automotive: <div>automotive</div>,
            pharmacy: <div>pharmacy</div>,
            loyalty: <div>loyalty</div>,
            variants: <div>variants</div>,
          }
        : {})}
    />
  )
}

function sharedKeys(container: HTMLElement): string[] {
  return Array.from(container.querySelectorAll('[data-product-section-key]'))
    .map((node) => node.getAttribute('data-product-section-key') ?? '')
}

describe('ProductSectionStack parity', () => {
  it('renders the exact same six shared keys in view and edit', () => {
    const view = render(<ViewStack />)
    expect(sharedKeys(view.container)).toEqual(['hero', 'general', 'pricing', 'inventory', 'suppliers', 'media'])
    view.unmount()

    const edit = render(<EditStack />)
    expect(sharedKeys(edit.container)).toEqual(['hero', 'general', 'pricing', 'inventory', 'suppliers', 'media'])
    expect(edit.container.querySelectorAll('#section-pricing')).toHaveLength(1)
  })

  it('places automotive/pharmacy/loyalty before suppliers and variants after media', () => {
    const { container } = render(<EditStack withExtensions />)
    const orderedKeys = Array.from(container.querySelectorAll(
      '[data-product-section-key], [data-product-extension-key]',
    )).map((node) => (
      node.getAttribute('data-product-section-key') ?? node.getAttribute('data-product-extension-key')
    ))

    expect(orderedKeys).toEqual([
      'hero',
      'general',
      'pricing',
      'inventory',
      'automotive',
      'pharmacy',
      'loyalty',
      'suppliers',
      'media',
      'variants',
    ])
  })

  it('keeps automotive optional in view and omits edit-only extensions by default', () => {
    const view = render(<ViewStack />)
    expect(view.container.querySelector('[data-product-extension-key="automotive"]')).not.toBeNull()
    expect(view.container.querySelector('[data-product-extension-key="pharmacy"]')).toBeNull()
    view.unmount()

    const edit = render(<EditStack />)
    expect(edit.container.querySelectorAll('[data-product-extension-key]')).toHaveLength(0)
  })
})
