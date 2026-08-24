/**
 * DocumentLineEditor — purchase-document unit-price default (campaign defect W2-6).
 *
 * A purchase order line must NEVER pre-fill the product's retail `sale_price`:
 * an operator who accepts that default books the goods receipt at retail and
 * inflates stock valuation / WAC (wave-2 report §F7: Dr 37 would have posted
 * 1 852,000 instead of 1 130,000).
 *
 * Required precedence on every purchase surface:
 *   1. supplier-specific purchase price  (no such source exists yet — see the
 *      resolver docblock in DocumentLineEditor.tsx)
 *   2. `products.purchase_price`
 *   3. EMPTY — the operator must type the price
 *
 * Sale documents keep defaulting to `sale_price` (regression guard below).
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { DocumentLineEditor, type DocumentLine } from '../DocumentLineEditor'
import type { ProductLineProduct } from '../../../../components/molecules/line-items/useProductLineLookup'

const PRODUCT: ProductLineProduct = {
  id: '11111111-1111-4111-8111-111111111111',
  name: 'Crème hydratante Bébé 200ml',
  sku: 'CREM-BEBE_200',
  sale_price: '24.900',
  purchase_price: '15.000',
  tax_rate: '7.00',
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'sales:lineItems.unitPrice': 'Unit price',
        'sales:lineItems.priceSource.productPurchasePrice': 'From purchase price',
        'sales:lineItems.priceSource.none': 'No purchase price on file',
      }
      const resolved = map[key] ?? key
      return options !== undefined && typeof options.amount === 'string'
        ? `${resolved} ${options.amount}`
        : resolved
    },
  }),
}))

vi.mock('../../../../stores/companyStore', () => {
  const state = { getCurrentCompany: () => null, currentCompanyId: null }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../../../../stores/authStore', () => {
  const state = { user: null }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

vi.mock('../../../../lib/api', () => ({ api: { get: vi.fn() }, apiPost: vi.fn() }))

vi.mock('../../../../components/organisms/AddQuickProductModal/AddQuickProductModal', () => ({
  AddQuickProductModal: () => null,
}))

vi.mock('../../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect', () => ({
  TaxConfigurationSelect: () => <span data-testid="tax-select">Tax</span>,
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: { line_designation_override_enabled: false },
    hasModule: () => false,
  }),
}))

// Stubbed entry bar: exposes the real `onAddProduct` callback behind a button so
// the test drives the production add-line path without the /products search.
vi.mock('../../../../components/molecules/line-items/LineItemEntryBar', () => ({
  LineItemEntryBar: ({
    onAddProduct,
  }: {
    onAddProduct: (product: ProductLineProduct, meta: { source: 'search'; incrementBy: number }) => void
  }) => (
    <button
      type="button"
      onClick={() => {
        onAddProduct(addedProduct, { source: 'search', incrementBy: 1 })
      }}
    >
      add-product
    </button>
  ),
}))

let addedProduct: ProductLineProduct = PRODUCT

function Harness({ documentType }: { documentType: string }) {
  const [lines, setLines] = useState<DocumentLine[]>([])
  return <DocumentLineEditor lines={lines} onChange={setLines} documentType={documentType} />
}

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}

async function addLine(documentType: string, product: ProductLineProduct): Promise<HTMLInputElement> {
  addedProduct = product
  render(<Harness documentType={documentType} />, { wrapper })
  await userEvent.click(screen.getByText('add-product'))
  return screen.getByLabelText('Unit price') as HTMLInputElement
}

describe('DocumentLineEditor — purchase-document unit-price default (W2-6)', () => {
  it('defaults a purchase-order line to products.purchase_price, never the sale price', async () => {
    const input = await addLine('purchase_order', PRODUCT)

    expect(input.value).toBe('15.000')
    expect(input.value).not.toBe('24.900')
  })

  it('leaves the purchase-order price EMPTY when the product has no purchase price', async () => {
    const input = await addLine('purchase_order', { ...PRODUCT, purchase_price: null })

    expect(input.value).toBe('')
  })

  it('treats a blank purchase price as absent (still EMPTY, never the sale price)', async () => {
    const input = await addLine('purchase_order', { ...PRODUCT, purchase_price: '' })

    expect(input.value).toBe('')
  })

  it('still defaults a sale document to the sale price', async () => {
    const input = await addLine('invoice', PRODUCT)

    expect(input.value).toBe('24.900')
  })

  it('tells the operator where the purchase default came from', async () => {
    await addLine('purchase_order', PRODUCT)

    expect(screen.getByText(/From purchase price/)).toBeInTheDocument()
  })

  it('tells the operator when no purchase price was on file', async () => {
    await addLine('purchase_order', { ...PRODUCT, purchase_price: null })

    expect(screen.getByText(/No purchase price on file/)).toBeInTheDocument()
  })
})
