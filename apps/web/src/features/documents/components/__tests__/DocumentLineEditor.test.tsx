/**
 * DocumentLineEditor — designation & notes wiring tests
 * TDD: Tests written FIRST; must fail until DesignationCell + NotesCell are wired in.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { DocumentLineEditor, type DocumentLine } from '../DocumentLineEditor'
import { apiPost } from '../../../../lib/api'

// ── Mocks ────────────────────────────────────────────────────────────────────

const companyConfigMock = vi.hoisted(() => ({
  enabledModules: ['Workshop'] as string[],
  purchaseBonusEnabled: false,
}))

const taxSelectMock = vi.hoisted(() =>
  vi.fn((props: { documentType?: string }) => (
    <span data-document-type={props.documentType ?? ''} data-testid="tax-select">Tax</span>
  ))
)

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const originalName = params?.['originalName']
      const originalNameText = typeof originalName === 'string' ? originalName : ''
      const code = typeof params?.['code'] === 'string' ? params['code'] : ''
      const map: Record<string, string> = {
        // DesignationCell keys
        'documents:lines.designation.editAriaLabel': 'Edit designation',
        'documents:lines.designation.overriddenTooltip': `Designation overridden — original: ${originalNameText}`,
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
        'sales:lineItems.freeQuantity': 'Free qty',
        'sales:lineItems.unitPrice': 'Unit Price',
        'sales:lineItems.priceEntryMode.unit': 'PU HT',
        'sales:lineItems.priceEntryMode.total': 'Total HT',
        'sales:lineItems.effectiveUnitCost': `Effective unit cost: ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.bonusSavings': `Bonus savings: ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.pricing.cost': `Cost ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.pricing.lastBuy': `Last buy ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.pricing.margin': `Margin ${String(params?.['percent'] ?? '')}%`,
        'sales:lineItems.pricing.details': 'Pricing details',
        'sales:lineItems.pricing.useSuggested': 'Use suggested',
        'sales:lineItems.pricing.suggested': `Suggested ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.pricing.lastSale': `Last sale ${String(params?.['amount'] ?? '')}`,
        'sales:lineItems.pricing.minimumMargin': `Minimum ${String(params?.['percent'] ?? '')}%`,
        'sales:lineItems.pricing.policyBlocked': `Margin blocked: ${String(params?.['permission'] ?? '')}`,
        'sales:lineItems.pricing.policyWarning': 'Margin warning',
        'sales:lineItems.discount': 'Discount',
        'sales:lineItems.taxPercent': 'Tax',
        'sales:lineItems.total': 'Total',
        'sales:lineItems.subtotal': 'Subtotal',
        'sales:lineItems.tax': 'Tax',
        'sales:lineItems.actions.dragToReorder': 'Drag to reorder',
        'sales:lineItems.actions.removeLine': 'Remove line',
        'sales:lineItems.actions.searchProducts': 'Search products',
        'sales:lineItems.actions.addBlankLine': 'Add blank line',
        'sales:lineItems.actions.clickToEdit': 'Click to edit',
        'sales:lineItems.entry.placeholder': 'Search or scan a product',
        'sales:lineItems.entry.productNotFound': `Product not found: ${code}`,
        'sales:lineItems.entry.requiresVariant': 'Choose a variant before adding this product',
        'sales:lineItems.productImagePlaceholder': 'No product image',
        'sales:lineItems.tabs.product': 'Product',
        'sales:lineItems.tabs.service': 'Service',
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

// Mock company store
vi.mock('../../../../stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (selector: (s: { currentCompanyId: string; getCurrentCompany: () => null }) => unknown) =>
    selector({
      currentCompanyId: 'company-1',
      getCurrentCompany: () => null,
    }),
    {
      getState: () => ({
        currentCompanyId: 'company-1',
      }),
    },
  ),
}))

vi.mock('../../../../stores/authStore', () => ({
  useAuthStore: Object.assign(
    (selector: (s: { user: { tenant_id: string } }) => unknown) =>
      selector({ user: { tenant_id: 'tenant-1' } }),
    {
      getState: () => ({
        user: { tenant_id: 'tenant-1' },
      }),
    },
  ),
}))

// Mock API (no network calls in unit tests)
vi.mock('../../../../lib/api', () => ({
  api: { get: vi.fn() },
  apiPost: vi.fn(),
}))

// Mock AddQuickProductModal — this organism brings in heavy deps (mutations, forms)
vi.mock('../../../components/organisms', () => ({
  AddQuickProductModal: () => null,
}))

// Mock TaxConfigurationSelect — brings in TaxConfigFormModal which needs QueryClient + mutations
vi.mock('../../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect', () => ({
  TaxConfigurationSelect: taxSelectMock,
}))

// Mock CompanyConfigContext — useLineDesignationFeature calls useCompanyConfig
vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: {
      line_designation_override_enabled: true,
      purchase_bonus_enabled: companyConfigMock.purchaseBonusEnabled,
    },
    hasModule: (moduleName: string) => companyConfigMock.enabledModules.includes(moduleName),
  }),
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
    free_quantity: '0',
    price_entry_mode: 'unit',
    ...overrides,
  }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('DocumentLineEditor — designation cells', () => {
  let onChange: ReturnType<typeof vi.fn>

  beforeEach(() => {
    onChange = vi.fn()
    companyConfigMock.enabledModules = ['Workshop']
    companyConfigMock.purchaseBonusEnabled = false
    vi.mocked(apiPost).mockReset()
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

  it('keeps subtotal, tax, and total calculations stable', () => {
    render(
      <DocumentLineEditor
        lines={[
          makeLine({
            id: 'line-a',
            quantity: 2,
            unit_price: 10,
            tax_rate: 20,
            line_total: 24,
          }),
          makeLine({
            id: 'line-b',
            quantity: 3,
            unit_price: 5,
            tax_rate: 10,
            line_total: 16.5,
          }),
        ]}
        onChange={onChange}
      />,
      { wrapper: createWrapper() },
    )

    expect(screen.getByText('EUR 35.00')).toBeInTheDocument()
    expect(screen.getByText('EUR 5.50')).toBeInTheDocument()
    expect(screen.getByText('EUR 40.50')).toBeInTheDocument()
  })

  it('renders the persistent line-entry bar when Workshop module is disabled', () => {
    companyConfigMock.enabledModules = []

    render(<DocumentLineEditor lines={[]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })

    expect(screen.getByRole('combobox', { name: 'Search or scan a product' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Service' })).not.toBeInTheDocument()
  })

  it('renders one persistent line-entry bar when Workshop module is enabled', () => {
    render(<DocumentLineEditor lines={[]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })

    expect(screen.getAllByRole('combobox', { name: 'Search or scan a product' })).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Add blank line' })).toBeInTheDocument()
  })

  it('does not pass a dead document-type token to the tax selector for sales orders', () => {
    render(
      <DocumentLineEditor
        documentType="sales_order"
        lines={[makeLine()]}
        onChange={onChange}
      />,
      { wrapper: createWrapper() },
    )

    expect(screen.getByTestId('tax-select')).toHaveAttribute('data-document-type', '')
    expect(taxSelectMock).toHaveBeenCalledWith(
      expect.not.objectContaining({ documentType: 'SALES_ORDER' }),
      undefined,
    )
  })

  it('recalculates a line total when quantity changes', async () => {
    const user = userEvent.setup()
    const line = makeLine({
      quantity: 2,
      unit_price: 10,
      tax_rate: 20,
      line_total: 24,
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, {
      wrapper: createWrapper(),
    })

    const quantityInput = screen.getAllByRole('spinbutton')[0]
    await user.clear(quantityInput)
    const rerenderedQuantityInput = screen.getAllByRole('spinbutton')[0]
    await user.type(rerenderedQuantityInput, '3')

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        quantity: '3',
        line_total: '36.000',
      }),
    ])
  })

  it('applies a line discount before calculating tax', async () => {
    const user = userEvent.setup()
    const line = makeLine({
      quantity: 1,
      unit_price: 100,
      tax_rate: 20,
      line_total: 120,
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, {
      wrapper: createWrapper(),
    })

    const discountInput = screen.getByRole('spinbutton', { name: 'Discount' })
    await user.clear(discountInput)
    await user.type(discountInput, '1')
    const rerenderedDiscountInput = screen.getByRole('spinbutton', { name: 'Discount' })
    await user.type(rerenderedDiscountInput, '0')

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        discount_percent: '10',
        discount_amount: null,
        line_total: '108.000',
      }),
    ])
    expect(screen.getByText('EUR 90.00')).toBeInTheDocument()
    expect(screen.getByText('EUR 18.00')).toBeInTheDocument()
    expect(screen.getAllByText('EUR 108.00')).toHaveLength(2)
  })

  it('hides purchase bonus controls when either the module or company flag is disabled', () => {
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = false

    render(<DocumentLineEditor documentType="purchase_order" lines={[makeLine()]} onChange={onChange} />, {
      wrapper: createWrapper(),
    })

    expect(screen.queryByRole('spinbutton', { name: 'Free qty' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Total HT' })).not.toBeInTheDocument()
  })

  it('shows purchase bonus controls and effective-cost facts when the module and company flag are enabled', () => {
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = true

    render(
      <DocumentLineEditor
        documentType="purchase_order"
        lines={[
          makeLine({
            quantity: 20,
            unit_price: 5,
            free_quantity: '1',
            line_total: '100.000',
            price_entry_mode: 'unit',
          }),
        ]}
        onChange={onChange}
      />,
      { wrapper: createWrapper() },
    )

    expect(screen.getByRole('spinbutton', { name: 'Free qty' })).toHaveValue(1)
    expect(screen.getByRole('button', { name: 'Total HT' })).toBeInTheDocument()
    expect(screen.getByText('Effective unit cost: EUR 4.76')).toBeInTheDocument()
    expect(screen.getByText('Bonus savings: EUR 5.00')).toBeInTheDocument()
  })

  it('keeps bonus quantity and total-mode price entry as strings in line updates', async () => {
    const user = userEvent.setup()
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = true
    const line = makeLine({
      quantity: 7,
      unit_price: 14.285,
      line_total: '100.000',
      free_quantity: '0',
      price_entry_mode: 'unit',
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          documentType="purchase_order"
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, { wrapper: createWrapper() })

    const freeQuantityInput = screen.getByRole('spinbutton', { name: 'Free qty' })
    await user.clear(freeQuantityInput)
    await user.type(screen.getByRole('spinbutton', { name: 'Free qty' }), '1')

    await user.click(screen.getByRole('button', { name: 'Total HT' }))
    const totalInput = screen.getByRole('spinbutton', { name: 'Unit Price' })
    fireEvent.change(totalInput, {
      target: { value: '100.000' },
    })
    fireEvent.blur(totalInput)

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        free_quantity: '1',
        price_entry_mode: 'total',
        line_total: '100.000',
        unit_price: '14.286',
      }),
    ])
  })

  it('round-trips total price entry as an HT extended amount without tax drift', async () => {
    const user = userEvent.setup()
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = true
    const line = makeLine({
      quantity: '3',
      unit_price: '10.000',
      tax_rate: '19',
      line_total: '35.700',
      free_quantity: '0',
      price_entry_mode: 'unit',
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          documentType="purchase_order"
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, { wrapper: createWrapper() })

    await user.click(screen.getByRole('button', { name: 'Total HT' }))
    expect(screen.getByRole('spinbutton', { name: 'Unit Price' })).toHaveValue(30)

    const totalInput = screen.getByRole('spinbutton', { name: 'Unit Price' })
    fireEvent.change(totalInput, {
      target: { value: '33.000' },
    })
    fireEvent.blur(totalInput)

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        price_entry_mode: 'total',
        line_total: '39.270',
        unit_price: '11.000',
      }),
    ])

    await user.click(screen.getByRole('button', { name: 'PU HT' }))
    await user.click(screen.getByRole('button', { name: 'Total HT' }))
    await user.click(screen.getByRole('button', { name: 'PU HT' }))
    await user.click(screen.getByRole('button', { name: 'Total HT' }))

    expect(screen.getByRole('spinbutton', { name: 'Unit Price' })).toHaveValue(33)
    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        price_entry_mode: 'total',
        line_total: '39.270',
        unit_price: '11.000',
      }),
    ])
  })

  it('round-trips total price entry with a percentage discount without double-discounting', async () => {
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = true
    const line = makeLine({
      quantity: '2',
      unit_price: '15.000',
      discount_percent: '10',
      discount_amount: null,
      tax_rate: '0',
      line_total: '27.000',
      free_quantity: '0',
      price_entry_mode: 'total',
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          documentType="purchase_order"
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, { wrapper: createWrapper() })

    const totalInput = screen.getByRole('spinbutton', { name: 'Unit Price' })
    fireEvent.change(totalInput, {
      target: { value: '36.000' },
    })
    fireEvent.blur(totalInput)

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        price_entry_mode: 'total',
        discount_percent: '10',
        discount_amount: null,
        line_total: '36.000',
        unit_price: '20.000',
      }),
    ])
  })

  it('round-trips total price entry with an absolute discount without double-discounting', async () => {
    companyConfigMock.enabledModules = ['Workshop', 'PurchaseBonus']
    companyConfigMock.purchaseBonusEnabled = true
    const line = makeLine({
      quantity: '2',
      unit_price: '17.500',
      discount_percent: null,
      discount_amount: '5.000',
      tax_rate: '0',
      line_total: '30.000',
      free_quantity: '0',
      price_entry_mode: 'total',
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          documentType="purchase_order"
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, { wrapper: createWrapper() })

    const totalInput = screen.getByRole('spinbutton', { name: 'Unit Price' })
    fireEvent.change(totalInput, {
      target: { value: '35.000' },
    })
    fireEvent.blur(totalInput)

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        price_entry_mode: 'total',
        discount_percent: null,
        discount_amount: '5.000',
        line_total: '35.000',
        unit_price: '20.000',
      }),
    ])
  })

  it('lazily fetches bulk pricing context on unit-price focus and renders the hint', async () => {
    const user = userEvent.setup()
    vi.mocked(apiPost).mockResolvedValue({
      items: {
        'prod-1': {
          currency: 'TND',
          cost_wac: '12.500000',
          last_purchase_cost: '11.900000',
          last_purchase_at: null,
          last_sale_to_partner: {
            unit_price: '18.000',
            at: '2026-06-15',
            document_no: 'INV-LATEST',
          },
          suggested_price: '16.250',
          target_margin_pct: '30.00',
          minimum_margin_pct: '15.00',
          policy: {
            level: 'green',
            allowed: true,
            requires_permission: null,
          },
        },
      },
    })

    render(
      <DocumentLineEditor
        partnerId="partner-1"
        lines={[
          makeLine({
            product_id: 'prod-1',
            unit_price: '16.250',
            line_total: '16.250',
          }),
        ]}
        onChange={onChange}
      />,
      { wrapper: createWrapper() },
    )

    expect(apiPost).not.toHaveBeenCalled()

    await user.click(screen.getByRole('spinbutton', { name: 'Unit Price' }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/line-entry/pricing-context/bulk', {
        partner_id: 'partner-1',
        lines: [
          {
            product_id: 'prod-1',
            variant_id: null,
            unit_price: '16.250',
          },
        ],
      })
    })
    expect(await screen.findByText('Cost 12.500000 · Last buy 11.900000 · Margin 30%')).toBeInTheDocument()
  })

  it('shows server-driven blocked margin policy and applies suggested price', async () => {
    const user = userEvent.setup()
    vi.mocked(apiPost).mockResolvedValue({
      items: {
        'prod-1': {
          currency: 'TND',
          cost_wac: '12.500000',
          last_purchase_cost: '11.900000',
          last_purchase_at: null,
          last_sale_to_partner: null,
          suggested_price: '16.250',
          target_margin_pct: '30.00',
          minimum_margin_pct: '15.00',
          policy: {
            level: 'orange',
            allowed: false,
            requires_permission: 'pricing.sell_below_minimum_margin',
          },
        },
      },
    })
    const line = makeLine({
      product_id: 'prod-1',
      quantity: '1',
      unit_price: '13.000',
      line_total: '13.000',
    })

    function ControlledEditor() {
      const [currentLines, setCurrentLines] = useState<DocumentLine[]>([line])
      return (
        <DocumentLineEditor
          partnerId="partner-1"
          lines={currentLines}
          onChange={(nextLines) => {
            onChange(nextLines)
            setCurrentLines(nextLines)
          }}
        />
      )
    }

    render(<ControlledEditor />, { wrapper: createWrapper() })

    await user.click(screen.getByRole('spinbutton', { name: 'Unit Price' }))

    expect(await screen.findByText('Margin blocked: pricing.sell_below_minimum_margin')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Pricing details' }))
    expect(screen.getByText('Suggested 16.250')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Use suggested' }))

    expect(onChange).toHaveBeenLastCalledWith([
      expect.objectContaining({
        unit_price: '16.250',
        line_total: '16.250',
      }),
    ])
  })
})
