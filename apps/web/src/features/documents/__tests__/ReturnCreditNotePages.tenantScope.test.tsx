import { QueryClient } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'

import { CreateCreditNotePage } from '../CreateCreditNotePage'
import { CreateReturnNotePage } from '../CreateReturnNotePage'
import { ReturnNoteDetailPage } from '../return-notes'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiPost: mockApiPost,
    api: {
      get: mockApiGet,
      post: mockApiPost,
    },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3, format: (value: number | string) => String(value) }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

type InvoiceSearchSelectProps = {
  onChange: (invoice: { id: string; partner_id: string }) => void
  label?: string
}

vi.mock('@/components/molecules/pickers/InvoiceSearchSelect', () => ({
  InvoiceSearchSelect: ({ onChange, label = 'invoice-select' }: InvoiceSearchSelectProps) => (
    <button
      type="button"
      onClick={() => {
        onChange({ id: 'invoice-1', partner_id: 'partner-1' })
      }}
    >
      {label}
    </button>
  ),
}))

type DeliveryNoteSearchSelectProps = {
  onChange: (deliveryNote: { id: string }) => void
  label?: string
}

vi.mock('@/components/molecules/pickers/DeliveryNoteSearchSelect', () => ({
  DeliveryNoteSearchSelect: ({ onChange, label = 'delivery-note-select' }: DeliveryNoteSearchSelectProps) => (
    <button
      type="button"
      onClick={() => {
        onChange({ id: 'delivery-note-1' })
      }}
    >
      {label}
    </button>
  ),
}))

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: ({ onChange }: { onChange: (partner: { id: string; name: string; type: 'customer' }) => void }) => (
    <button
      type="button"
      onClick={() => {
        onChange({ id: 'partner-1', name: 'Partner A', type: 'customer' })
      }}
    >
      partner-select
    </button>
  ),
}))

vi.mock('@/components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: () => <div>line-editor</div>,
}))

vi.mock('../components/ReturnReasonSelect', () => ({
  ReturnReasonSelect: () => <select aria-label="return-reason" defaultValue="defective"><option value="defective">defective</option></select>,
}))

vi.mock('../components/ReturnConditionSelect', () => ({
  ReturnConditionSelect: () => <select aria-label="return-condition" defaultValue=""><option value="">none</option></select>,
}))

vi.mock('../components/RefundMethodSelect', () => ({
  RefundMethodSelect: () => <select aria-label="refund-method" defaultValue=""><option value="">none</option></select>,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

const documentDetail = {
  document_number: 'DOC-1',
  document_date: '2026-05-11',
  partner: { id: 'partner-1', name: 'Partner' },
  partner_id: 'partner-1',
  total: '10.000',
  lines: [
    {
      id: 'line-1',
      product_id: 'product-1',
      product_code: 'P-1',
      product_name: 'Product 1',
      description: 'Product 1',
      quantity: 1,
      unit_price: '10.000',
      tax_rate: '0.000',
      total: '10.000',
    },
  ],
}

const returnNote = {
  id: 'return-note-1',
  type: 'return_note',
  document_number: 'RN-1',
  document_date: '2026-05-11',
  status: 'draft',
  partner_name: 'Partner',
  partner_email: null,
  partner: { id: 'partner-1', name: 'Partner' },
  lines: [],
  payload: {
    return_reason: 'defective',
  },
  metadata: {
    return_reason: 'defective',
    source_invoice_id: 'invoice-1',
    source_delivery_note_id: null,
    linked_credit_note_id: null,
    return_condition: null,
    refund_method: null,
    notes: null,
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/invoices/')) return { data: { data: documentDetail } }
    if (url.startsWith('/delivery-notes/')) return { data: { data: documentDetail } }
    if (url.endsWith('/related')) {
      return {
        data: {
          data: {
            ancestors: [],
            current: {
              id: returnNote.id,
              type: returnNote.type,
              document_number: returnNote.document_number,
              document_date: returnNote.document_date,
              status: returnNote.status,
              total: '0.000',
              currency: 'TND',
            },
            descendants: [],
          },
        },
      }
    }
    if (url.startsWith('/documents/')) return { data: { data: returnNote } }
    if (url.startsWith('/return-notes/')) return { data: { data: returnNote } }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { data: { id: 'created-1' } } })
})

afterEach(() => {
  resetTenant()
})

describe('return and credit note page tenant scope', () => {
  it('scopes create credit note invoice reads and invalidations (.141-.143)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['credit-notes', 'tenant-A', 'company-1'], ['tenant-A-credit'])
    queryClient.setQueryData(['documents', 'tenant-A', 'company-1'], ['tenant-A-documents'])
    queryClient.setQueryData(['credit-notes', 'tenant-B', 'company-2'], ['tenant-B-credit'])

    renderWithProviders(<CreateCreditNotePage />, { queryClient })

    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.sourceInvoice' }))

    await waitFor(() => {
      expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-A', 'company-1'])).toEqual(documentDetail)
    })

    await user.click(screen.getByRole('button', { name: 'sales:creditNotes.form.create' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/credit-notes', expect.objectContaining({
        partner_id: 'partner-1',
        source_invoice_id: 'invoice-1',
      }))
      expect(queryClient.getQueryState(['credit-notes', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
      expect(queryClient.getQueryState(['documents', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
      expect(mockNavigate).toHaveBeenCalledWith('/sales/credit-notes/created-1')
    })
    expect(queryClient.getQueryState(['credit-notes', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes create return note source reads and invalidation (.144-.146)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['return-notes', 'tenant-A', 'company-1'], ['tenant-A-return'])
    queryClient.setQueryData(['return-notes', 'tenant-B', 'company-2'], ['tenant-B-return'])

    renderWithProviders(<CreateReturnNotePage />, { queryClient })

    const deliveryButtons = screen.getAllByRole('button', { name: 'sales:documents.types.delivery_note' })
    await user.click(deliveryButtons[deliveryButtons.length - 1])

    await waitFor(() => {
      expect(queryClient.getQueryData(['delivery-note', 'delivery-note-1', 'tenant-A', 'company-1'])).toEqual(documentDetail)
    })

    await user.click(screen.getByRole('button', { name: 'sales:documents.types.invoice' }))
    const invoiceButtons = screen.getAllByRole('button', { name: 'sales:documents.types.invoice' })
    await user.click(invoiceButtons[invoiceButtons.length - 1])

    await waitFor(() => {
      expect(queryClient.getQueryData(['invoice', 'invoice-1', 'tenant-A', 'company-1'])).toEqual(documentDetail)
    })

    await user.click(screen.getByRole('button', { name: 'sales:returnNotes.form.create' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/return-notes', expect.objectContaining({
        source_invoice_id: 'invoice-1',
      }))
      expect(queryClient.getQueryState(['return-notes', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
      expect(mockNavigate).toHaveBeenCalledWith('/sales/return-notes/created-1')
    })
    expect(queryClient.getQueryState(['return-notes', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes return note detail reads and confirm invalidation (.155-.157)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['return-notes', 'tenant-A', 'company-1'], ['tenant-A-return'])
    queryClient.setQueryData(['return-notes', 'tenant-B', 'company-2'], ['tenant-B-return'])

    renderWithProviders(
      <Routes>
        <Route path="/sales/return-notes/:id" element={<ReturnNoteDetailPage />} />
      </Routes>,
      { queryClient, route: '/sales/return-notes/return-note-1' },
    )

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'return-note-1', 'tenant-A', 'company-1'])).toEqual(returnNote)
    })

    await user.click(screen.getByRole('button', { name: 'common:actions.confirm' }))
    await user.click(screen.getByRole('button', { name: 'common:confirm' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/return-notes/return-note-1/confirm')
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/documents/return-note-1')).toHaveLength(2)
      expect(queryClient.getQueryState(['document', 'return-note-1', 'tenant-A', 'company-1'])?.isInvalidated).toBe(false)
    })
    expect(queryClient.getQueryState(['return-notes', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('does not fetch document pages without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<CreateCreditNotePage />)
    renderWithProviders(
      <Routes>
        <Route path="/sales/return-notes/:id" element={<ReturnNoteDetailPage />} />
      </Routes>,
      { route: '/sales/return-notes/return-note-1' },
    )

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
