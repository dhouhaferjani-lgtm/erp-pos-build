import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { DocumentForm } from '../DocumentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: '' }))
const mockPath = vi.hoisted(() => ({ current: '/sales/invoices/new' }))
const mockTranslate = vi.hoisted(() => vi.fn((key: string, fallback?: string) => fallback ?? key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPatch: mockApiPatch,
    apiPost: mockApiPost,
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useLocation: () => ({ pathname: mockPath.current, search: '' }),
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

vi.mock('@/hooks/useDraftAutoSave', () => ({
  useDraftAutoSave: () => ({ draftId: null, isSaving: false, lastSavedAt: null }),
}))

vi.mock('@/components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: () => null,
}))

vi.mock('../components/PurchaseOrderAdditionalCosts', () => ({
  PurchaseOrderAdditionalCosts: () => null,
}))

vi.mock('@/components/molecules/StickyFormFooter/StickyFormFooter', () => ({
  StickyFormFooter: ({ children }: { children: ReactNode }) => <div>{children}</div>,
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

function documentFixture() {
  return {
    id: 'doc-1',
    type: 'invoice',
    partner_id: 'partner-1',
    issue_date: '2026-05-11',
    due_date: '2026-05-20',
    notes: 'Existing',
    external_document_number: '',
    external_document_date: '',
    lines: [],
  }
}

function partnerFixture() {
  return {
    id: 'partner-1',
    name: 'Partner A',
    type: 'customer',
    email: 'partner-a@example.test',
    city: 'Tunis',
  }
}

function createdPartnerFixture() {
  return {
    id: 'partner-2',
    name: 'Partner B',
    type: 'customer',
    email: null,
    phone: null,
    address: null,
    city: null,
    postal_code: null,
    country: null,
    tax_id: null,
    notes: null,
  }
}

function Probe({ queryKey, queryFn }: { queryKey: readonly unknown[]; queryFn: () => Promise<unknown> }) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  mockPath.current = '/sales/invoices/new'
  mockRouteId.current = ''
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/partners?')) return { data: { data: [partnerFixture()] } }
    if (url === '/partners/partner-1') return { data: { data: partnerFixture() } }
    return { data: { data: documentFixture() } }
  })
  mockApiPatch.mockResolvedValue({ id: 'doc-1' })
  mockApiPost.mockImplementation(async (url: string) => (url === '/partners' ? createdPartnerFixture() : { id: 'doc-new' }))
})

afterEach(() => {
  resetTenant()
})

describe('DocumentForm tenant scope', () => {
  async function pickPartner() {
    const user = userEvent.setup()
    await user.click(await screen.findByRole('combobox'))
    await user.click(await screen.findByRole('option', { name: /Partner A/i }))
  }

  it('wraps edit document read key and gates missing tenant/company (.147)', async () => {
    mockRouteId.current = 'doc-1'
    mockPath.current = '/sales/invoices/doc-1/edit'
    const queryClient = createClient()
    render(<DocumentForm documentType="invoice" />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'invoice', 'doc-1', 'tenant-A', 'company-1'])).toBeDefined()
    })
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/partners/partner-1')
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<DocumentForm documentType="invoice" />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates create/update cascades and leaves tenant-B cache untouched (.148-.152)', async () => {
    const queryClient = createClient()
    let documentsCalls = 0
    let invoiceListCalls = 0

    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })
    queryClient.setQueryData(['invoice', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice-list' })

    const { unmount } = render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['invoice']} queryFn={async () => [`invoice-list-${++invoiceListCalls}`]} />
        <DocumentForm documentType="invoice" />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(documentsCalls).toBe(1)
      expect(invoiceListCalls).toBe(1)
    })

    await pickPartner()
    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'actions.save' }))
    })

    await waitFor(() => {
      expect(documentsCalls).toBe(2)
      expect(invoiceListCalls).toBe(2)
    })

    unmount()
    mockRouteId.current = 'doc-1'
    mockPath.current = '/sales/invoices/doc-1/edit'
    let detailCalls = 0
    mockApiGet.mockImplementation(async () => {
      detailCalls += 1
      return { data: { data: documentFixture() } }
    })
    queryClient.setQueryData(['document', 'invoice', 'doc-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-detail' })

    render(
      <>
        <Probe queryKey={['documents']} queryFn={async () => [`documents-${++documentsCalls}`]} />
        <Probe queryKey={['invoice']} queryFn={async () => [`invoice-list-${++invoiceListCalls}`]} />
        <DocumentForm documentType="invoice" />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(documentsCalls).toBe(3)
      expect(invoiceListCalls).toBe(3)
      expect(detailCalls).toBe(1)
    })

    const issueDateInput = await screen.findByLabelText('sales:documents.issueDate', { exact: false })
    await userEvent.clear(issueDateInput)
    await userEvent.type(issueDateInput, '2026-05-11')

    await act(async () => {
      await userEvent.click(await screen.findByRole('button', { name: 'actions.save' }))
    })

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
      expect(documentsCalls).toBe(4)
      expect(invoiceListCalls).toBe(4)
      expect(detailCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
    expect(queryClient.getQueryData(['invoice', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice-list' })
    expect(queryClient.getQueryData(['document', 'invoice', 'doc-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-detail' })
  })

  it('uses the real PartnerPicker inline-create affordance without touching another tenant cache (.153)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['pickers', 'partner', 'customer', false, '', 'tenant-B', 'company-1'], { marker: 'tenant-B-picker' })
    mockApiGet.mockImplementation(async (url: string) => {
      if (url.startsWith('/partners?')) return { data: { data: [] } }
      return { data: { data: documentFixture() } }
    })

    render(<DocumentForm documentType="invoice" />, { wrapper: wrapper(queryClient) })

    const user = userEvent.setup()
    await user.click(await screen.findByRole('combobox'))

    await waitFor(() => {
      const [latestUrl] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
      expect(latestUrl).toContain('/partners?')
      expect(latestUrl).toContain('type=customer')
    })
    expect(await screen.findByRole('button', { name: 'partner.addNew.customer' })).toBeInTheDocument()
    expect(queryClient.getQueryData(['pickers', 'partner', 'customer', false, '', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-picker' })
  })
})
