import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DocumentForm, buildLinePayload, computeLinesDirty } from './DocumentForm'

const draftAutoSaveState = vi.hoisted(() => ({
  draftId: undefined as string | undefined,
  isSaving: false,
  lastSavedAt: null as Date | null,
  autosavePending: false,
  autosaveFailed: false,
}))

const routerState = vi.hoisted(() => ({
  id: '',
  pathname: '/sales/invoices/new',
  search: '',
}))

const reactQueryState = vi.hoisted(() => ({
  document: undefined as unknown,
  mutationPayloads: [] as unknown[],
}))

const partnerA = vi.hoisted(() => ({
  id: 'partner-1',
  name: 'Partner A',
  type: 'customer' as const,
  email: 'partner-a@example.test',
  city: 'Tunis',
}))

// i18n → return the key so assertions are deterministic. Tests that need to
// assert a *translated* string (e.g. the FR heading) seed `i18nState.dict`
// with the keys they care about; everything else falls through to the
// fallback-or-key behaviour the rest of the suite relies on.
const i18nState = vi.hoisted(() => ({ dict: {} as Record<string, string> }))
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => i18nState.dict[key] ?? fallback ?? key,
  }),
}))

// router
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: routerState.id }),
  useLocation: () => ({ pathname: routerState.pathname, search: routerState.search }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// decouple from network — the form's own queries/mutations
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

vi.mock('../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../../hooks/useDraftAutoSave', () => ({
  useDraftAutoSave: () => draftAutoSaveState,
}))

// child components that fetch / render heavy trees — stub them out
vi.mock('../../components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: ({
    lines,
    onChange,
  }: {
    lines: Array<{
      id: string
      product_id: string
      service_id?: string
      product_name: string
      description: string
      quantity: string | number
      unit_price: string | number
      tax_rate: string | number
      line_total: string | number
      price_entry_mode?: 'unit' | 'total'
      is_service?: boolean
    }>
    onChange: (lines: Array<{
      id: string
      product_id: string
      service_id?: string
      product_name: string
      description: string
      quantity: string | number
      unit_price: string | number
      tax_rate: string | number
      line_total: string | number
      price_entry_mode?: 'unit' | 'total'
      is_service?: boolean
    }>) => void
  }) => (
    <div data-testid="line-editor">
      {lines.map((line) => (
        <div
          key={line.id}
          data-testid={`line-${line.id}`}
          data-service={line.is_service ? 'true' : 'false'}
          data-service-id={line.service_id ?? ''}
          data-product-id={line.product_id ?? ''}
        >
          {line.is_service ? 'Service badge' : 'Product line'}: {line.product_name}
        </div>
      ))}
      <button
        type="button"
        onClick={() => {
          onChange([
            ...lines,
            {
              id: 'line-service-1',
              product_id: '',
              service_id: 'service-1',
              product_name: 'Oil change labor',
              description: 'Oil change labor',
              quantity: '1',
              unit_price: '80.000',
              tax_rate: '0',
              line_total: '80.000',
              price_entry_mode: 'unit',
              is_service: true,
            },
          ])
        }}
      >
        Add mocked service line
      </button>
    </div>
  ),
}))
vi.mock('./components/PurchaseOrderAdditionalCosts', () => ({
  PurchaseOrderAdditionalCosts: ({ documentId }: { documentId: string }) => (
    <section aria-label="Additional costs">
      <div>Cost row for {documentId}</div>
    </section>
  ),
}))
// ---------------------------------------------------------------------------
// Bug 3 — unit tests for the pure linesDirty helper
// ---------------------------------------------------------------------------
describe('computeLinesDirty', () => {
  const line = { id: 'a', product_id: 'p', quantity: 1, unit_price: 10, tax_rate: 0, line_total: 10 }

  it('returns false when no lines and never saved (blank new doc)', () => {
    expect(computeLinesDirty(null, [])).toBe(false)
  })

  it('returns true when lines exist and never autosaved', () => {
    expect(computeLinesDirty(null, [line])).toBe(true)
  })

  it('returns true when all lines cleared after a successful autosave', () => {
    // autosave had one line, user removed it → snapshot mismatch → guard fires
    expect(computeLinesDirty(JSON.stringify([line]), [])).toBe(true)
  })

  it('returns false when lines match the saved snapshot', () => {
    expect(computeLinesDirty(JSON.stringify([line]), [line])).toBe(false)
  })

  it('returns true when lines differ from saved snapshot', () => {
    const line2 = { ...line, id: 'b' }
    expect(computeLinesDirty(JSON.stringify([line]), [line, line2])).toBe(true)
  })
})

describe('buildLinePayload', () => {
  it('sends service_id and omits product_id for service lines', () => {
    const payload = buildLinePayload({
      id: 'line-service-1',
      product_id: '',
      service_id: 'service-1',
      product_name: 'Oil change labor',
      description: 'Oil change labor',
      quantity: '1',
      unit_price: '80.000',
      tax_rate: '0',
      line_total: '80.000',
      price_entry_mode: 'unit',
      is_service: true,
    })

    expect(payload).toMatchObject({
      service_id: 'service-1',
      quantity: '1',
      unit_price: '80.000',
      line_total: '80.000',
    })
    expect(payload).not.toHaveProperty('product_id')
  })
})

describe('DocumentForm (canonical layout)', () => {
  beforeEach(() => {
    draftAutoSaveState.draftId = undefined
    draftAutoSaveState.isSaving = false
    draftAutoSaveState.lastSavedAt = null
    draftAutoSaveState.autosavePending = false
    draftAutoSaveState.autosaveFailed = false
    routerState.id = ''
    routerState.pathname = '/sales/invoices/new'
    routerState.search = ''
    reactQueryState.document = undefined
    reactQueryState.mutationPayloads = []
    i18nState.dict = {}
  })

  it('renders a single page-level heading with the entity name', () => {
    render(<DocumentForm documentType="invoice" />)
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
    // The doc-type label now flows through i18n (was hardcoded English) — the
    // heading is composed of the add action + the translated type key.
    expect(h1s[0]).toHaveTextContent('sales:documents.types.invoice')
  })

  it('renders a fully translated heading for purchase_order (no mixed English leak)', () => {
    // Regression: the FR create page previously showed "Ajouter Purchase Order"
    // because the doc-type label came from a hardcoded English map. Seed the FR
    // strings for the two composed keys and assert the heading is fully French.
    i18nState.dict = {
      'actions.add': 'Ajouter',
      'sales:documents.types.purchase_order': "Bon d'achat",
    }
    render(<DocumentForm documentType="purchase_order" />)
    const h1 = screen.getAllByRole('heading', { level: 1 })[0]
    expect(h1).toHaveTextContent("Ajouter Bon d'achat")
    expect(h1).not.toHaveTextContent(/Purchase Order/)
  })

  it('groups fields under a section heading', () => {
    render(<DocumentForm documentType="invoice" />)
    // Canonical forms section their fields under an <h2>; the original flat
    // form had none. This is the red→green driver for the migration.
    expect(screen.getByRole('heading', { level: 2 })).toBeInTheDocument()
  })

  it('renders labelled form fields via FormField', () => {
    render(<DocumentForm documentType="invoice" />)
    expect(
      screen.getByLabelText('sales:documents.issueDate', { exact: false }),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('sales:documents.notes', { exact: false }),
    ).toBeInTheDocument()
  })

  it('renders a submit button', () => {
    render(<DocumentForm documentType="invoice" />)
    const save = screen.getByRole('button', { name: 'actions.save' })
    expect(save).toHaveAttribute('type', 'submit')
  })

  it('renders a Save split button with a Save & Close option', () => {
    render(<DocumentForm documentType="invoice" />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    expect(screen.getByRole('menuitem', { name: 'actions.saveAndClose' })).toBeInTheDocument()
  })

  it('renders purchase order additional costs against an autosaved draft id during creation', () => {
    draftAutoSaveState.draftId = 'draft-po-1'

    render(<DocumentForm documentType="purchase_order" />)

    expect(screen.getByRole('region', { name: 'Additional costs' })).toBeInTheDocument()
    expect(screen.getByText('Cost row for draft-po-1')).toBeInTheDocument()
  })

  it('submits service lines with service_id and without product_id', async () => {
    const user = userEvent.setup()
    render(<DocumentForm documentType="invoice" />)

    await user.click(screen.getByRole('combobox'))
    await user.click(screen.getByRole('option', { name: /Partner A/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Add mocked service line' }))
    fireEvent.click(screen.getByRole('button', { name: 'actions.save' }))

    await waitFor(() => {
      expect(reactQueryState.mutationPayloads).toHaveLength(1)
    })

    const payload = reactQueryState.mutationPayloads[0] as { lines: Array<Record<string, unknown>> }
    expect(payload.lines[0]).toMatchObject({
      service_id: 'service-1',
      description: 'Oil change labor',
    })
    expect(payload.lines[0]).not.toHaveProperty('product_id')
  })

  // Regression (money-campaign W1b MTP-DOC-07/10, defect 1): the edit-populate
  // effect read `document.issue_date`, but the documents API emits
  // `document_date` (DocumentData::fromModel). The REQUIRED Issue Date input
  // therefore loaded empty on every edit and react-hook-form blocked the
  // submit client-side, with no PATCH and no toast.
  it('populates the Issue Date from the API `document_date` when editing', async () => {
    routerState.id = 'invoice-1'
    routerState.pathname = '/sales/invoices/invoice-1/edit'
    reactQueryState.document = {
      id: 'invoice-1',
      type: 'invoice',
      status: 'draft',
      partner_id: 'partner-1',
      document_date: '2026-07-10',
      due_date: null,
      notes: null,
      external_document_number: null,
      external_document_date: null,
      lines: [],
    }

    render(<DocumentForm documentType="invoice" />)

    const issueDate = await screen.findByLabelText('sales:documents.issueDate', { exact: false })
    await waitFor(() => {
      expect(issueDate).toHaveValue('2026-07-10')
    })
  })

  it('normalises an ISO-8601 `document_date` to the date-input value', async () => {
    routerState.id = 'invoice-1'
    routerState.pathname = '/sales/invoices/invoice-1/edit'
    reactQueryState.document = {
      id: 'invoice-1',
      type: 'invoice',
      status: 'draft',
      partner_id: 'partner-1',
      document_date: '2026-07-10T00:00:00+01:00',
      due_date: null,
      notes: null,
      external_document_number: null,
      external_document_date: null,
      lines: [],
    }

    render(<DocumentForm documentType="invoice" />)

    const issueDate = await screen.findByLabelText('sales:documents.issueDate', { exact: false })
    await waitFor(() => {
      expect(issueDate).toHaveValue('2026-07-10')
    })
  })

  it('surfaces a visible required-field error when saving with no partner selected', async () => {
    render(<DocumentForm documentType="invoice" />)

    fireEvent.click(screen.getByRole('button', { name: 'actions.save' }))

    // The partner FormField had no `error` prop, so a blocked submit produced
    // NO message anywhere — a completely silent no-op.
    // The partner rule's message is `t('validation.required', 'This field is
    // required')`; the mocked `t` falls through to the fallback.
    await waitFor(() => {
      expect(screen.getByText('This field is required')).toBeInTheDocument()
    })
    expect(reactQueryState.mutationPayloads).toHaveLength(0)
  })

  // Regression (money-campaign W1b MTP-DOC-23, defect 5): CreditNoteController
  // requires `reason`, but the generic form never collected it, so a standalone
  // credit note could only ever 422.
  it('collects a credit-note reason and sends it in the payload', async () => {
    const user = userEvent.setup()
    routerState.pathname = '/sales/credit-notes/new'
    render(<DocumentForm documentType="credit_note" />)

    const reason = screen.getByLabelText('sales:creditNotes.reason.title', { exact: false })
    // Two comboboxes on a credit note: the PartnerPicker (first) and the
    // reason <select>.
    await user.click(screen.getAllByRole('combobox')[0])
    await user.click(screen.getByRole('option', { name: /Partner A/i }))
    await user.selectOptions(reason, 'return')
    fireEvent.click(screen.getByRole('button', { name: 'Add mocked service line' }))
    fireEvent.click(screen.getByRole('button', { name: 'actions.save' }))

    await waitFor(() => {
      expect(reactQueryState.mutationPayloads).toHaveLength(1)
    })
    expect(reactQueryState.mutationPayloads[0]).toMatchObject({ reason: 'return' })
  })

  it('does not send a reason for non credit-note document types', async () => {
    const user = userEvent.setup()
    render(<DocumentForm documentType="invoice" />)

    expect(screen.queryByLabelText('sales:creditNotes.reason.title', { exact: false })).toBeNull()

    await user.click(screen.getByRole('combobox'))
    await user.click(screen.getByRole('option', { name: /Partner A/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Add mocked service line' }))
    fireEvent.click(screen.getByRole('button', { name: 'actions.save' }))

    await waitFor(() => {
      expect(reactQueryState.mutationPayloads).toHaveLength(1)
    })
    expect(reactQueryState.mutationPayloads[0]).not.toHaveProperty('reason')
  })

  it('restores service identity for loaded service lines', async () => {
    routerState.id = 'invoice-1'
    routerState.pathname = '/sales/invoices/invoice-1/edit'
    reactQueryState.document = {
      id: 'invoice-1',
      type: 'invoice',
      status: 'draft',
      partner_id: 'partner-1',
      issue_date: '2026-07-10',
      due_date: null,
      notes: null,
      external_document_number: null,
      external_document_date: null,
      lines: [
        {
          id: 'line-existing-service',
          document_id: 'invoice-1',
          product_id: null,
          service_id: 'service-1',
          product_name: 'Oil change labor',
          product_code: 'SRV-OIL',
          line_number: 1,
          description: 'Oil change labor',
          quantity: '1',
          free_quantity: '0',
          unit_price: '80.000',
          price_entry_mode: 'unit',
          discount_percent: null,
          discount_amount: null,
          tax_rate: '0',
          line_total: '80.000',
          notes: null,
          designation_default_snapshot: 'Oil change labor',
          quantity_decimals: null,
          requires_batch_tracking: false,
          is_service: true,
        },
      ],
    }

    render(<DocumentForm documentType="invoice" />)

    const row = await screen.findByTestId('line-line-existing-service')
    expect(row).toHaveTextContent('Service badge')
    expect(row).toHaveAttribute('data-service', 'true')
    expect(row).toHaveAttribute('data-service-id', 'service-1')
    expect(row).toHaveAttribute('data-product-id', '')
  })
})
