/**
 * DocumentForm — a blank unit price must BLOCK the submit, on every document type.
 *
 * Gate r1 finding 1 (BLOCKER). W2-6 introduced an EMPTY purchase-price default and
 * coerced `'' → '0'` inside `buildLinePayload`, which is consumed by BOTH the draft
 * autosave AND the final submit. Two consequences:
 *
 *   (a) the new EMPTY default persisted as a CONFIRMED purchase-order line at 0.000 —
 *       PurchaseOrderService::confirm() has no zero-price guard, the goods receipt
 *       posts Dr 37 at 0.000 and drags WAC down, and the three-way match only raises
 *       an ADVISORY price_variance under the shipped `warn` default;
 *   (b) a SALES invoice line whose price the operator cleared used to 422 server-side
 *       (CreateDocumentRequest `lines.*.unit_price` => required|numeric) and now
 *       silently bills zero.
 *
 * Required: the coercion lives ONLY on the autosave draft payload; the submit path
 * passes the blank through, is blocked client-side, and says so inline.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DocumentForm, buildLinePayload, buildAutoSaveLinePayload } from '../DocumentForm'
import type { DocumentLine } from '@/components/documents/DocumentLineEditor'

const draftAutoSaveState = vi.hoisted(() => ({
  draftId: undefined as string | undefined,
  isSaving: false,
  lastSavedAt: null as Date | null,
  autosavePending: false,
  autosaveFailed: false,
}))

const routerState = vi.hoisted(() => ({ id: '', pathname: '/sales/invoices/new', search: '' }))

const reactQueryState = vi.hoisted(() => ({
  document: undefined as unknown,
  mutationPayloads: [] as unknown[],
}))

// Captures the draft payload handed to useDraftAutoSave on the latest render.
const autoSaveState = vi.hoisted(() => ({ lastDraftData: null as unknown }))

const partnerA = vi.hoisted(() => ({
  id: 'partner-1',
  name: 'Partner A',
  type: 'customer' as const,
  email: 'partner-a@example.test',
  city: 'Tunis',
}))

// The line the stub editor adds — mutated per test.
const stubLine = vi.hoisted(() => ({
  value: {
    id: 'line-1',
    product_id: 'prod-1',
    product_name: 'Crème hydratante Bébé 200ml',
    description: 'Crème hydratante Bébé 200ml',
    quantity: '30',
    unit_price: '' as string,
    tax_rate: '7.00',
    line_total: '0.000',
    price_entry_mode: 'unit' as const,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => (typeof fallback === 'string' ? fallback : key),
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: routerState.id }),
  useLocation: () => ({ pathname: routerState.pathname, search: routerState.search }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: (options: { queryKey?: readonly unknown[] }) => {
      const queryKey = options.queryKey ?? []
      if (queryKey[0] === 'pickers' && queryKey[1] === 'partner') {
        return { data: [partnerA], isLoading: false, isError: false }
      }
      if (queryKey[0] === 'partner') {
        return { data: partnerA, isLoading: false, isError: false }
      }
      return { data: reactQueryState.document, isLoading: false, isError: false }
    },
    useMutation: () => ({
      mutate: vi.fn((payload: unknown) => {
        reactQueryState.mutationPayloads.push(payload)
      }),
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../../../hooks/useDraftAutoSave', () => ({
  useDraftAutoSave: (draftData: unknown) => {
    autoSaveState.lastDraftData = draftData
    return draftAutoSaveState
  },
}))

// Stub editor: adds ONE line (whose unit_price the test controls) and renders any
// per-line invalidity the form pushes back down, so "blocked + flagged" is provable.
vi.mock('../../../components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: ({
    lines,
    onChange,
    invalidLineIds,
  }: {
    lines: DocumentLine[]
    onChange: (lines: DocumentLine[]) => void
    invalidLineIds?: ReadonlySet<string>
  }) => (
    <div data-testid="line-editor">
      {lines.map((line) => (
        <div key={line.id} data-testid={`line-${line.id}`} data-invalid={invalidLineIds?.has(line.id) === true ? 'true' : 'false'}>
          {line.product_name}
        </div>
      ))}
      <button
        type="button"
        onClick={() => {
          onChange([...lines, { ...stubLine.value } as DocumentLine])
        }}
      >
        Add mocked line
      </button>
    </div>
  ),
}))

vi.mock('../components/PurchaseOrderAdditionalCosts', () => ({
  PurchaseOrderAdditionalCosts: () => null,
}))

function makeLine(overrides: Partial<DocumentLine> = {}): DocumentLine {
  return {
    id: 'line-1',
    product_id: 'prod-1',
    product_name: 'Test Product',
    description: 'Test Product',
    quantity: '2.0000',
    unit_price: '10.000',
    tax_rate: '19.00',
    line_total: '20.000',
    free_quantity: '0',
    price_entry_mode: 'unit',
    ...overrides,
  }
}

async function selectPartnerAndSave(): Promise<void> {
  const user = userEvent.setup()
  await user.click(screen.getByRole('combobox'))
  await user.click(await screen.findByRole('option', { name: /Partner A/i }))
  await user.click(screen.getByRole('button', { name: 'actions.save' }))
}

describe('buildLinePayload / buildAutoSaveLinePayload — blank unit price', () => {
  it('SUBMIT payload passes a blank unit price through untouched (server must reject it)', () => {
    expect(buildLinePayload(makeLine({ unit_price: '' })).unit_price).toBe('')
  })

  it('SUBMIT payload never rewrites a blank price to zero', () => {
    expect(buildLinePayload(makeLine({ unit_price: '' })).unit_price).not.toBe('0')
  })

  it('AUTOSAVE payload still coerces a blank price to "0" so the keystroke-rate draft save cannot 422', () => {
    expect(buildAutoSaveLinePayload(makeLine({ unit_price: '' })).unit_price).toBe('0')
  })

  it('AUTOSAVE payload leaves a real price exactly as entered', () => {
    expect(buildAutoSaveLinePayload(makeLine({ unit_price: '15.000' })).unit_price).toBe('15.000')
  })
})

describe('DocumentForm — a blank unit price blocks the submit', () => {
  beforeEach(() => {
    draftAutoSaveState.draftId = undefined
    routerState.id = ''
    reactQueryState.document = undefined
    reactQueryState.mutationPayloads = []
    autoSaveState.lastDraftData = null
    stubLine.value = { ...stubLine.value, unit_price: '' }
  })

  it('blocks a PURCHASE ORDER whose line has no unit price, and says so', async () => {
    routerState.pathname = '/purchases/orders/new'
    render(<DocumentForm documentType="purchase_order" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    await selectPartnerAndSave()

    await waitFor(() => {
      expect(screen.getByText('sales:documents.errors.unitPriceRequired')).toBeInTheDocument()
    })
    expect(reactQueryState.mutationPayloads).toHaveLength(0)
    expect(screen.getByTestId('line-line-1')).toHaveAttribute('data-invalid', 'true')
  })

  it('blocks a SALES INVOICE whose line price was cleared (used to 422 server-side)', async () => {
    routerState.pathname = '/sales/invoices/new'
    render(<DocumentForm documentType="invoice" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    await selectPartnerAndSave()

    await waitFor(() => {
      expect(screen.getByText('sales:documents.errors.unitPriceRequired')).toBeInTheDocument()
    })
    expect(reactQueryState.mutationPayloads).toHaveLength(0)
  })

  it('still sends the draft autosave for an unpriced line, with the price as "0"', async () => {
    routerState.pathname = '/purchases/orders/new'
    render(<DocumentForm documentType="purchase_order" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))

    await waitFor(() => {
      const draft = autoSaveState.lastDraftData as { lines: Array<{ unit_price: unknown }> } | null
      expect(draft?.lines[0]?.unit_price).toBe('0')
    })
  })

  it('submits normally once the operator types a price', async () => {
    routerState.pathname = '/purchases/orders/new'
    stubLine.value = { ...stubLine.value, unit_price: '15.000', line_total: '481.500' }
    render(<DocumentForm documentType="purchase_order" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    await selectPartnerAndSave()

    await waitFor(() => {
      expect(reactQueryState.mutationPayloads).toHaveLength(1)
    })
    const payload = reactQueryState.mutationPayloads[0] as { lines: Array<{ unit_price: unknown }> }
    expect(payload.lines[0].unit_price).toBe('15.000')
    expect(screen.queryByText('sales:documents.errors.unitPriceRequired')).not.toBeInTheDocument()
  })
})
