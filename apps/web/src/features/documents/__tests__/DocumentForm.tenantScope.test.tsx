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

vi.mock('@/components/molecules/pickers/PartnerPicker', async () => {
  const { useQueryClient } = await vi.importActual<typeof import('@tanstack/react-query')>('@tanstack/react-query')
  const { useAuthStore } = await vi.importActual<typeof import('@/stores/authStore')>('@/stores/authStore')
  const { useCompanyStore } = await vi.importActual<typeof import('@/stores/companyStore')>('@/stores/companyStore')

  return {
  PartnerPicker: ({
    allowNewInline,
    onChange,
    value,
  }: {
    allowNewInline?: boolean
    onChange: (value: { id: string; name: string; type: 'customer' } | null) => void
    value: string
  }) => {
    const queryClient = useQueryClient()
    const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
    const companyId = useCompanyStore((state) => state.currentCompanyId)

    return (
      <div>
        <select
          aria-label="partner-select"
          value={value}
          onChange={(event) => {
            onChange(event.target.value === '' ? null : {
              id: event.target.value,
              name: 'Partner A',
              type: 'customer',
            })
          }}
        >
          <option value="">Select partner</option>
          <option value="partner-1">Partner A</option>
        </select>
        {allowNewInline ? (
          <button
            type="button"
            onClick={() => {
              onChange({ id: 'partner-2', name: 'Partner B', type: 'customer' })
              void queryClient.invalidateQueries({
                predicate: (q) => {
                  const key = q.queryKey
                  return (
                    key.length >= 3 &&
                    key[0] === 'partners' &&
                    key[key.length - 2] === tenantId &&
                    key[key.length - 1] === companyId
                  )
                },
              })
            }}
          >
            add-partner
          </button>
        ) : null}
      </div>
    )
  },
  }
})

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
  mockApiGet.mockResolvedValue({ data: { data: documentFixture() } })
  mockApiPatch.mockResolvedValue({ id: 'doc-1' })
  mockApiPost.mockResolvedValue({ id: 'doc-new' })
})

afterEach(() => {
  resetTenant()
})

describe('DocumentForm tenant scope', () => {
  it('wraps edit document read key and gates missing tenant/company (.147)', async () => {
    mockRouteId.current = 'doc-1'
    mockPath.current = '/sales/invoices/doc-1/edit'
    const queryClient = createClient()
    render(<DocumentForm documentType="invoice" />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document', 'invoice', 'doc-1', 'tenant-A', 'company-1'])).toBeDefined()
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

    await userEvent.selectOptions(await screen.findByLabelText('partner-select'), 'partner-1')
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

    await userEvent.selectOptions(await screen.findByLabelText('partner-select'), 'partner-1')
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

  it('invalidates partner creation against active tenant only (.153)', async () => {
    const queryClient = createClient()
    let partnersCalls = 0
    queryClient.setQueryData(['partners', 'tenant-B', 'company-1'], { marker: 'tenant-B-partners' })

    render(
      <>
        <Probe queryKey={['partners']} queryFn={async () => [`partners-${++partnersCalls}`]} />
        <DocumentForm documentType="invoice" />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(partnersCalls).toBe(1)
    })

    await act(async () => {
      await userEvent.click(screen.getByRole('button', { name: 'add-partner' }))
    })

    await waitFor(() => {
      expect(partnersCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['partners', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-partners' })
  })
})
