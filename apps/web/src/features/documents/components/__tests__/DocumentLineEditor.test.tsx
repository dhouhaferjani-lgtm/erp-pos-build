/**
 * DocumentLineEditor — designation & notes wiring tests
 * TDD: Tests written FIRST; must fail until DesignationCell + NotesCell are wired in.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { DocumentLineEditor, type DocumentLine } from '../DocumentLineEditor'

// ── Mocks ────────────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const originalName = params?.['originalName']
      const map: Record<string, string> = {
        // DesignationCell keys
        'documents:lines.designation.editAriaLabel': 'Edit designation',
        'documents:lines.designation.overriddenTooltip': `Designation overridden — original: ${String(originalName ?? '')}`,
        'documents:lines.designation.resetLink': 'Reset to product name',
        'documents:lines.designation.resetAriaLabel': 'Reset designation to product name',
        'documents:lines.designation.resetDisabledTooltip': 'Product no longer exists',
        'documents:lines.designation.emptyHint': 'Designation cannot be empty',
        // NotesCell keys
        'documents:lines.additionalDescription.addAriaLabel': 'Add additional description',
        'documents:lines.additionalDescription.editAriaLabel': 'Additional description',
        'documents:lines.additionalDescription.placeholder': 'e.g. 2.5h × 60/hr by mechanic John',
        'documents:lines.additionalDescription.addLink': 'Add note',
        // DocumentLineEditor keys
        'sales:lineItems.title': 'Line Items',
        'sales:lineItems.empty.title': 'No items yet.',
        'sales:lineItems.empty.description': 'Add a product or service.',
        'sales:lineItems.article': 'Article',
        'sales:lineItems.description': 'Description',
        'sales:lineItems.quantity': 'Qty',
        'sales:lineItems.unitPrice': 'Unit Price',
        'sales:lineItems.taxPercent': 'Tax',
        'sales:lineItems.total': 'Total',
        'sales:lineItems.subtotal': 'Subtotal',
        'sales:lineItems.tax': 'Tax',
        'sales:lineItems.actions.dragToReorder': 'Drag to reorder',
        'sales:lineItems.actions.removeLine': 'Remove line',
        'sales:lineItems.actions.searchProducts': 'Search products',
        'sales:lineItems.actions.addBlankLine': 'Add blank line',
        'sales:lineItems.actions.clickToEdit': 'Click to edit',
        'sales:lineItems.serviceBadge': 'Service',
        'common:table.actionsColumn': 'Actions',
      }
      let result = map[key] ?? key
      if (params) {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

// Mock company store — returns null company (formatCurrency falls back gracefully)
vi.mock('../../../stores/companyStore', () => ({
  useCompanyStore: (selector: (s: { getCurrentCompany: () => null }) => unknown) =>
    selector({ getCurrentCompany: () => null }),
}))

// Mock API (no network calls in unit tests)
vi.mock('../../../lib/api', () => ({
  api: { get: vi.fn() },
}))

// Mock AddQuickProductModal — this organism brings in heavy deps (mutations, forms)
vi.mock('../../../components/organisms', () => ({
  AddQuickProductModal: () => null,
}))

// Mock TaxConfigurationSelect — brings in TaxConfigFormModal which needs QueryClient + mutations
vi.mock('../../../components/atoms/TaxConfigurationSelect', () => ({
  TaxConfigurationSelect: () => <span data-testid="tax-select">Tax</span>,
}))

// Mock CompanyConfigContext — useLineDesignationFeature calls useCompanyConfig
vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ config: { line_designation_override_enabled: true } }),
}))

// ── Helpers ───────────────────────────────────────────────────────────────────

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })
  function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
  return Wrapper
}

function makeLine(overrides: Partial<DocumentLine> = {}): DocumentLine {
  return {
    id: crypto.randomUUID(),
    product_id: crypto.randomUUID(),
    product_name: 'Original product',
    description: 'Original product',
    designation_default_snapshot: 'Original product',
    notes: null,
    quantity: 1,
    unit_price: 10,
    tax_rate: 0,
    line_total: 10,
    ...overrides,
  }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('DocumentLineEditor — designation cells', () => {
  let onChange: ReturnType<typeof vi.fn>

  beforeEach(() => {
    onChange = vi.fn()
  })

  it('shows overridden indicator when description differs from snapshot', () => {
    const line = makeLine({
      description: 'Custom name',
      designation_default_snapshot: 'Original product',
    })
    render(<DocumentLineEditor lines={[line]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })
    // DesignationCell renders a role="status" span when description !== snapshot
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('shows edit affordance (pencil) for editable designation', () => {
    const line = makeLine()
    render(<DocumentLineEditor lines={[line]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })
    expect(screen.getByRole('button', { name: 'Edit designation' })).toBeInTheDocument()
  })

  it('notes cell is rendered when notes has a value', () => {
    const line = makeLine({ notes: 'Some extra info' })
    render(<DocumentLineEditor lines={[line]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })
    expect(screen.getByText('Some extra info')).toBeInTheDocument()
  })

  it('calls onChange with updated description when designation is committed', async () => {
    const user = userEvent.setup()
    const line = makeLine({ description: 'Old name', designation_default_snapshot: 'Old name' })
    render(<DocumentLineEditor lines={[line]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })

    // Enter edit mode via pencil button
    await user.click(screen.getByRole('button', { name: 'Edit designation' }))
    const input = screen.getByRole('textbox')
    await user.clear(input)
    await user.type(input, 'New name')
    await user.keyboard('{Enter}')

    expect(onChange).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({ description: 'New name' }),
      ])
    )
  })

  it('calls onChange with updated notes when notes cell is committed', async () => {
    const user = userEvent.setup()
    const line = makeLine({ notes: null })
    render(<DocumentLineEditor lines={[line]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })

    // Enter notes edit via the add affordance button
    await user.click(screen.getByRole('button', { name: 'Add additional description' }))
    const textarea = screen.getByRole('textbox', { name: 'Additional description' })
    await user.type(textarea, 'New note')
    await user.keyboard('{Enter}')

    expect(onChange).toHaveBeenCalledWith(
      expect.arrayContaining([
        expect.objectContaining({ notes: 'New note' }),
      ])
    )
  })

  it('pencil edit button is not rendered in readonly mode', () => {
    const line = makeLine()
    render(<DocumentLineEditor lines={[line]} onChange={onChange} readonly={true} />, {
      wrapper: createWrapper(),
    })
    expect(screen.queryByRole('button', { name: 'Edit designation' })).toBeNull()
  })

  it('overridden indicator is still shown in readonly mode', () => {
    const line = makeLine({
      description: 'Custom name',
      designation_default_snapshot: 'Original product',
    })
    render(<DocumentLineEditor lines={[line]} onChange={onChange} readonly={true} />, {
      wrapper: createWrapper(),
    })
    expect(screen.getByRole('status')).toBeInTheDocument()
  })
})
