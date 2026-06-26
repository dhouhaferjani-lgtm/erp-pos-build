/**
 * DocumentLineEditor — per-unit quantity step.
 * The qty input must step by the line's unit precision: pieces (quantity_decimals 0)
 * → step "1"; kg (3) → "0.001"; absent → fallback "0.0001".
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type ReactNode } from 'react'
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
    id: crypto.randomUUID(),
    product_id: crypto.randomUUID(),
    product_name: 'Product',
    description: 'Product',
    notes: null,
    quantity: 1,
    unit_price: 10,
    tax_rate: 0,
    line_total: 10,
    ...overrides,
  }
}

function stepFor(line: DocumentLine): string | null {
  render(<DocumentLineEditor lines={[line]} onChange={vi.fn()} />, { wrapper: createWrapper() })
  return screen.getAllByLabelText('Qty')[0].getAttribute('step')
}

describe('DocumentLineEditor — per-unit quantity step', () => {
  it('steps by 1 for a pieces line (quantity_decimals 0)', () => {
    expect(stepFor(makeLine({ quantity_decimals: 0 }))).toBe('1')
  })

  it('steps fractionally for a kg line (quantity_decimals 3)', () => {
    expect(stepFor(makeLine({ quantity_decimals: 3 }))).toBe('0.001')
  })

  it('falls back to 0.0001 when the line carries no unit precision', () => {
    expect(stepFor(makeLine())).toBe('0.0001')
  })
})
