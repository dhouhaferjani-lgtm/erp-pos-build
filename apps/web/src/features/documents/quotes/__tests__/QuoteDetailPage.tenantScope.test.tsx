import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { QuoteDetailPage } from '../QuoteDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'quote-1' }))
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-1', currency: 'TND' } }),
}))

vi.mock('../../components/DocumentHeader', () => ({
  DocumentHeader: ({
    actions,
    children,
  }: {
    actions?: ReactNode
    children?: ReactNode
  }) => (
    <div>
      {actions}
      {children}
    </div>
  ),
}))

vi.mock('../../components/DocumentActionBar', () => ({
  DocumentActionBar: ({
    onConfirm,
    onConvert,
  }: {
    onConfirm?: () => void
    onConvert?: () => void
  }) => (
    <div>
      <button type="button" onClick={onConfirm}>confirm-quote</button>
      <button type="button" onClick={onConvert}>convert-quote</button>
    </div>
  ),
}))

vi.mock('../../components/DocumentTotals', () => ({ DocumentTotals: () => null }))
vi.mock('../../components/RelatedDocumentsTab', () => ({ RelatedDocumentsTab: () => null }))
vi.mock('../../components/DocumentAttachments', () => ({ DocumentAttachments: () => null }))

vi.mock('../../hooks/useRelatedDocuments', () => ({
  useRelatedDocuments: () => ({ data: { descendants: [] } }),
}))

vi.mock('../../hooks/useLineDesignationFeature', () => ({
  useLineDesignationFeature: () => false,
}))

vi.mock('../../hooks', () => ({
  useDownloadPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePreviewPdf: () => ({ isPending: false, mutate: vi.fn() }),
  usePrintPdf: () => ({ isPending: false, mutate: vi.fn() }),
  useRevertDocument: () => ({ isPending: false, mutate: vi.fn() }),
  useSendDocumentEmail: () => ({ isPending: false, mutate: vi.fn() }),
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    isOpen,
    onConfirm,
    title,
  }: {
    isOpen: boolean
    onConfirm: () => void
    title: string
  }) => (isOpen ? <button type="button" onClick={onConfirm}>{title}</button> : null),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function quoteFixture() {
  return {
    id: 'quote-1',
    type: 'quote',
    status: 'draft',
    document_number: 'QT-1',
    document_date: '2026-05-11',
    valid_until: '2026-05-30',
    partner_id: 'partner-1',
    partner_name: 'Partner A',
    subtotal: '100.00',
    tax_amount: '0.00',
    total: '100.00',
    notes: null,
    lines: [
      {
        id: 'line-1',
        product_id: 'product-1',
        product_name: 'Workshop service',
        description: 'Diagnostic labor',
        quantity: '1.00',
        unit_price: '100.00',
        tax_rate: '0.00',
        line_total: '100.00',
        notes: null,
        designation_default_snapshot: 'Diagnostic labor',
        discount_percent: null,
        discount_amount: null,
        requires_batch_tracking: false,
      },
    ],
  }
}

function mockQuoteResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/quotes/quote-1') return { data: { data: quoteFixture() } }
    return { data: { data: [] } }
  })
}

function Probe({ queryKey, queryFn }: { queryKey: readonly unknown[]; queryFn: () => Promise<unknown> }) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  mockRouteId.current = 'quote-1'
  setTenant('tenant-A', 'company-1')
  mockQuoteResponses()
  mockApiPost.mockResolvedValue({ id: 'order-1' })
})

afterEach(() => {
  resetTenant()
})

describe('QuoteDetailPage tenant scope', () => {
  it('wraps quote detail read key and gates missing tenant/company (.230)', async () => {
    const queryClient = createClient()
    render(<QuoteDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'quote', 'quote-1', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<QuoteDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates confirm and conversion success cascades for only active tenant (.231-.234)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <QuoteDetailPage />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/quotes/quote-1')).toHaveLength(1)
      expect(documentsCalls).toBe(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'confirm-quote' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'documents.confirmTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/quotes/quote-1')).toHaveLength(2)
      expect(documentsCalls).toBe(2)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-quote' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'quotes.convertToOrderTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/quotes/quote-1')).toHaveLength(3)
      expect(documentsCalls).toBe(3)
    })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
  })

  it('invalidates conversion error detail path with scoped exact key (.235)', async () => {
    const queryClient = createClient()
    mockApiPost.mockRejectedValue(new Error('conversion failed'))

    render(<QuoteDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/quotes/quote-1')).toHaveLength(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'convert-quote' }))
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'quotes.convertToOrderTitle' }))
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/quotes/quote-1')).toHaveLength(2)
    })
  })

  it('renders quote lines through the shared line-items table contract', async () => {
    render(<QuoteDetailPage />, { wrapper: wrapper(createClient()) })

    expect(await screen.findByRole('columnheader', { name: 'lineItems.item' })).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'documents.description' })).not.toBeInTheDocument()
    expect(screen.getByText('Diagnostic labor')).toBeInTheDocument()
  })
})
