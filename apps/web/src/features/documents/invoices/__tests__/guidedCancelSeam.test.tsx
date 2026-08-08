import { QueryClient } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { renderWithProviders } from '@/test/renderWithProviders'

import { InvoiceDetailPage } from '../InvoiceDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockToastSuccess = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    api: { get: mockApiGet, post: mockApiPost },
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    // Interpolation is echoed, not dropped — M2 is precisely about values reaching the
    // copy, so a `t` that ignored its options would hide the defect under test.
    t: (key: string, options?: Record<string, unknown>) =>
      options && Object.keys(options).length > 0 ? `${key}:${JSON.stringify(options)}` : key,
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: mockToastSuccess, error: vi.fn() },
}))

// Unrelated sub-tabs of the invoice page. Stubbed so this file tests the CANCEL SEAM and
// not the whole detail screen — `RelatedDocumentsTab` in particular reads collections this
// fixture does not carry and would take the tree down with it.
vi.mock('../../components/RelatedDocumentsTab', () => ({
  RelatedDocumentsTab: () => <div>related-documents-tab</div>,
}))

vi.mock('../../components/DocumentAttachments', () => ({
  DocumentAttachments: () => <div>document-attachments</div>,
}))

vi.mock('../../components/CreditNoteList', () => ({
  CreditNoteList: () => <div>credit-note-list</div>,
}))

vi.mock('@/components/organisms/RecordPaymentModal', () => ({
  RecordPaymentModal: () => <div>record-payment-modal</div>,
}))

const INVOICE_ID = 'inv-1'

const invoice = {
  id: INVOICE_ID,
  type: 'invoice',
  status: 'posted',
  document_number: 'INV-2026-0001',
  document_date: '2026-08-01',
  due_date: null,
  valid_until: null,
  currency: 'TND',
  subtotal: '100.000',
  tax_amount: '19.000',
  total: '119.000',
  notes: null,
  internal_notes: null,
  partner_id: 'partner-1',
  partner_name: 'Customer',
  partner_email: null,
  source_document_id: null,
  source_document_number: null,
  source_document_type: null,
  converted_to_order_id: null,
  fully_delivered: null,
  fully_invoiced: null,
  goods_received: null,
  payment_status: null,
  amount_paid: null,
  balance_due: '119.000',
  outstanding_amount: '119.000',
  external_document_number: null,
  external_document_date: null,
  vehicle_context: null,
  lines: [],
  return_decision: null,
}

const canCancel = {
  can_cancel: true,
  reason_code: null,
  status: 'posted',
  requires_return_decision: true,
  goods_issued: true,
  delivered_quantities: [],
  return_decision: null,
}

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles: [],
      // Real permissions rather than a mocked hook: `usePermissions` reads the auth
      // store, so seeding it exercises the same gate the app does.
      permissions: ['invoices.cancel', 'invoices.view', 'documents.update'],
      email_verified_at: null,
    },
    isAuthenticated: true,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1' })
}

function renderPage(queryClient: QueryClient) {
  return renderWithProviders(
    <Routes>
      <Route path="/sales/invoices/:id" element={<InvoiceDetailPage />} />
    </Routes>,
    { route: `/sales/invoices/${INVOICE_ID}`, queryClient },
  )
}

function createClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  })
}

/**
 * Gate CF round 1 — the modal↔page SEAM.
 *
 * The three blockers the gate found all lived here, and every unit test that appeared to
 * cover them passed injected props into `CancelInvoiceModal` — so none of them could see
 * the wiring at all. These tests mount the REAL `InvoiceDetailPage` and let the real
 * modal drive: no `CancelInvoiceModal` props are constructed by the test.
 */
describe('guided cancel — page ↔ modal seam', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockToastSuccess.mockReset()
    setTenant()

    mockApiGet.mockImplementation((url: string) => {
      if (url === `/invoices/${INVOICE_ID}`) return Promise.resolve({ data: { data: invoice } })
      if (url === `/invoices/${INVOICE_ID}/can-cancel`) return Promise.resolve(canCancel)
      return Promise.resolve({ data: { data: {} } })
    })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, isAuthenticated: false })
    useCompanyStore.setState({ currentCompanyId: null })
  })

  async function openModal(user: ReturnType<typeof userEvent.setup>) {
    await screen.findByText('INV-2026-0001')
    const cancelAction = await screen.findByText('documents.cancel')
    await user.click(cancelAction)
    await screen.findByText('sales:invoices.cancelFlow.reasonLabel')
  }

  /**
   * B1. `useCancelInvoice` invalidated `['invoice', id]`, but this page stores its
   * document under `tenantScopedKey(['document', 'invoice', id])`. `queryKey` is a
   * positional PREFIX matcher and `'document' !== 'invoice'`, so after a successful
   * cancel the badge still read *posted*, the Cancel action stayed live, and the T16
   * recorded-decision block never appeared — with no error shown.
   */
  it('invalidates the key the page actually stores the invoice under', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    mockApiPost.mockResolvedValue({
      data: {
        data: invoice,
        return_decision: { mode: 'no_return', returned_on: null, return_note: null },
        message: 'ok',
      },
    })

    renderPage(queryClient)
    await openModal(user)

    await user.type(screen.getByRole('textbox'), 'Duplicate')
    await user.click(screen.getByRole('radio', { name: /option3/ }))
    await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith(
        `/invoices/${INVOICE_ID}/cancel`,
        expect.objectContaining({
          reason: 'Duplicate',
          return_decision: { mode: 'no_return' },
        }),
      )
    })

    // The behavioural property, not the flag: an ACTIVE query that is invalidated
    // refetches immediately (and `isInvalidated` flips straight back), so the observable
    // consequence is that the page re-reads the document. Under the old
    // `['invoice', id]` key it never did — the badge kept reading *posted* and the T16
    // recorded-decision block never appeared.
    await waitFor(() => {
      const invoiceReads = mockApiGet.mock.calls.filter(
        (call) => call[0] === `/invoices/${INVOICE_ID}`,
      ).length
      expect(invoiceReads).toBeGreaterThan(1)
    })

    // And the key really is the one the page stores under.
    expect(queryClient.getQueryData(tenantScopedKey(['document', 'invoice', INVOICE_ID]))).toBeDefined()
  })

  /**
   * B2. Two live refusal paths carry no `error.code` — a Laravel `ValidationException`
   * (`{message, errors}`) and the surviving generic flat envelope
   * (`{error: <string>, code: <string>}`) that T6 deliberately left in place. Against
   * both, `extractErrorCode` yields undefined, and the banner was gated on a truthy code:
   * the user clicked Cancel and NOTHING happened — no banner, no inline error, no toast.
   */
  it('renders actionable feedback for a 422 that carries no readable error code', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    // The surviving flat envelope, verbatim.
    mockApiPost.mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { error: 'Something went wrong', code: 'Something went wrong' } },
      message: 'Request failed with status code 422',
    })

    renderPage(queryClient)
    await openModal(user)

    await user.type(screen.getByRole('textbox'), 'Duplicate')
    await user.click(screen.getByRole('radio', { name: /option3/ }))
    await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('sales:invoices.cancelFlow.errors.unknown')
    })

    // And the modal stays open so the user can retry rather than losing their input.
    expect(screen.getByText('sales:invoices.cancelFlow.reasonLabel')).toBeInTheDocument()
  })

  /**
   * M2. The page passed `details: body.error` — the whole `{code, message, details}`
   * object — while the renderer nests the payload one level deeper. Both
   * `details.product_id` and `details.remaining_returnable` were undefined, so the banner
   * interpolated EMPTY strings. Plan §2 makes naming the product mandatory precisely
   * because CF-D7 removed the line UI, so this is the one refusal that is unactionable
   * without its details.
   */
  it('passes error.details through so the quantity refusal names the product', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    mockApiPost.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          error: {
            code: 'RETURN_EXCEEDS_DELIVERED_QUANTITY',
            message: 'too much',
            details: { product_id: 'prod-9', remaining_returnable: '2.0000' },
          },
        },
      },
      message: 'Request failed with status code 422',
    })

    renderPage(queryClient)
    await openModal(user)

    await user.type(screen.getByRole('textbox'), 'Duplicate')
    await user.click(screen.getByRole('radio', { name: /option1/ }))
    await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

    await waitFor(() => {
      const alert = screen.getByRole('alert')
      expect(alert).toHaveTextContent('prod-9')
      expect(alert).toHaveTextContent('2.0000')
    })
  })

  /**
   * B3. The modal mounts while `/can-cancel` is still in flight — and permanently if that
   * endpoint errors, since `checkCancellable()` returns 422 on any exception. With
   * `requires_return_decision` defaulting to FALSE the modal rendered no goods question
   * at all and posted `not_applicable` for an invoice with delivered physical goods.
   */
  it('never posts not_applicable while can-cancel is unresolved', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    mockApiGet.mockImplementation((url: string) => {
      if (url === `/invoices/${INVOICE_ID}`) return Promise.resolve({ data: { data: invoice } })
      // Permanently failing can-cancel — the state B3 says the modal can be stuck in.
      if (url === `/invoices/${INVOICE_ID}/can-cancel`) return Promise.reject(new Error('boom'))
      return Promise.resolve({ data: { data: {} } })
    })

    renderPage(queryClient)
    await openModal(user)

    // The goods question is still asked — failing CLOSED.
    expect(screen.getByText('sales:invoices.cancelFlow.goodsQuestion')).toBeInTheDocument()

    await user.type(screen.getByRole('textbox'), 'Duplicate')
    await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

    // Nothing is posted at all: submit is blocked until the server has answered.
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' })).toBeDisabled()
    })
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  /**
   * The success path end to end, including the toast link the owner ruling requires
   * ("the user finds it under return notes" is not satisfied by a bare toast).
   */
  it('posts the chosen mode and surfaces the created return note', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    mockApiPost.mockResolvedValue({
      data: {
        data: invoice,
        return_decision: {
          mode: 'will_return',
          returned_on: null,
          return_note: { id: 'rn-1', document_number: 'RN-2026-0007', status: 'draft' },
        },
        message: 'ok',
      },
    })

    renderPage(queryClient)
    await openModal(user)

    await user.type(screen.getByRole('textbox'), 'Coming back')
    await user.click(screen.getByRole('radio', { name: /option1/ }))
    await user.click(screen.getByRole('button', { name: 'sales:invoices.cancelFlow.submit' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith(
        `/invoices/${INVOICE_ID}/cancel`,
        expect.objectContaining({ return_decision: { mode: 'will_return' } }),
      )
    })

    await waitFor(() => {
      expect(mockToastSuccess).toHaveBeenCalledWith(
        expect.stringContaining('sales:invoices.cancelFlow.success.withReturnNote'),
        expect.objectContaining({
          action: expect.objectContaining({
            label: 'sales:invoices.cancelFlow.success.viewReturnNote',
          }),
        }),
      )
      // The return note's number reaches the copy — the ruling's "the user finds it under
      // return notes" needs the toast to identify it.
      expect(String(mockToastSuccess.mock.calls[0][0])).toContain('RN-2026-0007')
    })
  })
})
