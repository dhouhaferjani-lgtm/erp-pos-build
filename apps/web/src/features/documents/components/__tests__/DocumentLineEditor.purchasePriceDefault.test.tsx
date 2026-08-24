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
import { fireEvent, render, screen } from '@testing-library/react'
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
        'sales:lineItems.priceSource.required': 'Enter the unit price',
      }
      const resolved = map[key] ?? key
      const amount = options?.['amount']
      return typeof amount === 'string' ? `${resolved} ${amount}` : resolved
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
  return screen.getByLabelText<HTMLInputElement>('Unit price')
}

function priceInput(): HTMLInputElement {
  return screen.getByLabelText<HTMLInputElement>('Unit price')
}

/** Types into the price cell without relying on focus (the row remounts on change). */
function setPrice(value: string): void {
  fireEvent.change(priceInput(), { target: { value } })
}

async function clearPriceOn(documentType: string, product: ProductLineProduct): Promise<HTMLInputElement> {
  await addLine(documentType, product)
  setPrice('')
  return priceInput()
}

/**
 * Gate r1 finding 3 (MAJOR). The blank-price warning was rendered from add-time
 * provenance state, which `handleUpdateLine` drops on ANY price edit. So the one
 * affordance protecting the EMPTY default vanished in exactly the state it exists
 * to flag: type a digit, delete it, and the field is blank and silent. The warning
 * must come from CURRENT line state.
 */
describe('DocumentLineEditor — blank-price warning survives an edit (W2-6 r1 #3)', () => {
  it('keeps warning after the operator types then clears a seeded purchase price', async () => {
    const input = await clearPriceOn('purchase_order', PRODUCT)

    expect(input.value).toBe('')
    expect(screen.getByText(/Enter the unit price/)).toBeInTheDocument()
  })

  it('keeps warning on a purchase line that never had a price and was then touched', async () => {
    const input = await clearPriceOn('purchase_order', { ...PRODUCT, purchase_price: null })

    expect(input.value).toBe('')
    expect(screen.getByText(/Enter the unit price|No purchase price on file/)).toBeInTheDocument()
  })

  it('warns on a SALES line whose price the operator cleared', async () => {
    const input = await clearPriceOn('invoice', PRODUCT)

    expect(input.value).toBe('')
    expect(screen.getByText(/Enter the unit price/)).toBeInTheDocument()
  })

  it('drops the provenance label once the operator overtypes the seeded price', async () => {
    await addLine('purchase_order', PRODUCT)
    setPrice('16')

    expect(screen.queryByText(/From purchase price/)).not.toBeInTheDocument()
  })
})

/**
 * Gate r1 findings 5 + 6 (MINOR): the provenance label must not echo the amount
 * already rendered in the adjacent MoneyInput, and the hint must be associated
 * with its input for screen readers.
 */
describe('DocumentLineEditor — price hint presentation (W2-6 r1 #5/#6)', () => {
  it('does not repeat the price figure in the provenance label', async () => {
    await addLine('purchase_order', PRODUCT)

    expect(screen.getByText('From purchase price')).toBeInTheDocument()
    expect(screen.queryByText(/From purchase price\s+15\.000/)).not.toBeInTheDocument()
  })

  it('associates the hint with the price input via aria-describedby', async () => {
    const input = await addLine('purchase_order', { ...PRODUCT, purchase_price: null })

    const describedBy = input.getAttribute('aria-describedby')
    expect(describedBy).not.toBeNull()
    expect(document.getElementById(describedBy ?? '')).toHaveTextContent(/No purchase price on file/)
  })
})

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
