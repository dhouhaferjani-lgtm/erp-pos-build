/**
 * DocumentLineEditor — a refused submit must mark the cell of the field that is
 * actually missing (gate r3 R3-3).
 *
 * `invalidLineIds` is PRICE-specific: it reddens the money input
 * (`DocumentLineEditor.tsx:852`, `:872`) and the focus jump targets
 * `line-price-input-<id>` (`:319-338`). Feeding a blank-DESIGNATION refusal
 * through it painted a correctly-filled price cell red while the toast said
 * "enter a designation". The sibling `invalidDescriptionLineIds` carries that
 * refusal to the designation cell instead. `DocumentForm` passes only
 * `invalidLineIds`, so its behaviour is unchanged — pinned by the last case.
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type ComponentProps, type ReactNode } from 'react'
import { DocumentLineEditor, type DocumentLine } from '../DocumentLineEditor'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'sales:lineItems.quantity': 'Qty',
      }
      return map[key] ?? key
    },
  }),
}))

vi.mock('../../../stores/companyStore', () => ({
  useCompanyStore: (selector: (s: { getCurrentCompany: () => null; currentCompanyId: null }) => unknown) =>
    selector({ getCurrentCompany: () => null, currentCompanyId: null }),
}))

vi.mock('../../../stores/authStore', () => ({
  useAuthStore: (selector: (s: { user: null }) => unknown) => selector({ user: null }),
}))

vi.mock('../../../lib/api', () => ({ api: { get: vi.fn() } }))

vi.mock('../../../components/organisms', () => ({
  AddQuickProductModal: () => null,
}))

vi.mock('../../../components/atoms/TaxConfigurationSelect', () => ({
  TaxConfigurationSelect: () => <span data-testid="tax-select">Tax</span>,
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: { line_designation_override_enabled: false },
    hasModule: () => false,
  }),
}))

function createWrapper() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function makeLine(overrides: Partial<DocumentLine> = {}): DocumentLine {
  return {
    id: 'line-1',
    product_id: 'product-1',
    product_name: 'Product',
    description: 'Product',
    notes: null,
    quantity: 1,
    unit_price: '10.000',
    tax_rate: 0,
    line_total: '10.000',
    ...overrides,
  }
}

const DESCRIPTION_HINT = 'sales:documents.errors.descriptionRequired'

function renderEditor(props: Partial<ComponentProps<typeof DocumentLineEditor>> = {}) {
  return render(
    <DocumentLineEditor lines={[makeLine()]} onChange={vi.fn()} {...props} />,
    { wrapper: createWrapper() },
  )
}

describe('DocumentLineEditor — refused-field marking', () => {
  it('shows the designation hint on the line refused for a blank designation', () => {
    renderEditor({ invalidDescriptionLineIds: new Set(['line-1']) })

    expect(screen.getByText(DESCRIPTION_HINT)).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent(DESCRIPTION_HINT)
  })

  it('wires the hint to the designation cell so the focus jump lands there', () => {
    const { container } = renderEditor({ invalidDescriptionLineIds: new Set(['line-1']) })

    const cell = container.querySelector('#line-description-line-1')
    expect(cell).not.toBeNull()
    expect(cell).toHaveAttribute('aria-describedby', 'line-description-hint-line-1')
    // Focusable so the editor can take the operator to it.
    expect(cell).toHaveAttribute('tabindex', '-1')
  })

  it('does NOT mark the designation when only the PRICE was refused — DocumentForm behaviour is unchanged', () => {
    renderEditor({ invalidLineIds: new Set(['line-1']) })

    expect(screen.queryByText(DESCRIPTION_HINT)).not.toBeInTheDocument()
  })

  it('marks nothing when the parent refuses nothing', () => {
    const { container } = renderEditor()

    expect(screen.queryByText(DESCRIPTION_HINT)).not.toBeInTheDocument()
    expect(container.querySelector('#line-description-line-1')).not.toHaveAttribute('tabindex')
  })
})
