import { QueryClient, QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { render, waitFor } from '@testing-library/react'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useCustomerHistorySearches } from '@/features/customer-history-audit/hooks/useCustomerHistorySearches'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { DocumentListPage } from '../DocumentListPage'
import { ReturnNoteListPage } from '../ReturnNoteListPage'
import { DocumentTotals } from '../components/DocumentTotals'
import { PaymentHistorySection } from '../components/PaymentHistorySection'
import { RelatedDocumentsPanel } from '../components/RelatedDocumentsPanel'
import { useRelatedDocuments } from '../hooks/useRelatedDocuments'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockFetchTaxBreakdown = vi.hoisted(() => vi.fn())
const mockFetchPaymentHistory = vi.hoisted(() => vi.fn())
const mockListCustomerHistorySearches = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
  }
})

vi.mock('@/hooks/usePageTitle', () => ({
  usePageTitle: vi.fn(),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 2 }),
}))

vi.mock('../api/taxApi', () => ({
  fetchTaxBreakdown: mockFetchTaxBreakdown,
}))

vi.mock('../api/paymentHistory', () => ({
  fetchPaymentHistory: mockFetchPaymentHistory,
}))

vi.mock('@/features/customer-history-audit/api/customerHistorySearchApi', () => ({
  listCustomerHistorySearches: mockListCustomerHistorySearches,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key,
  }),
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

function wrapper(queryClient: QueryClient, route = '/sales/invoices') {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter initialEntries={[route]}>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

function CacheProbe({ onClient }: { onClient: (client: QueryClient) => void }) {
  const queryClient = useQueryClient()
  onClient(queryClient)
  return null
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation((url: string) => {
    if (url.startsWith('/invoices')) return Promise.resolve({ data: { data: [], meta: { total: 0 } } })
    if (url.startsWith('/return-notes')) return Promise.resolve({ data: { data: [] } })
    if (url === '/documents/doc-1/related') {
      return Promise.resolve({
        data: {
          data: {
            source_documents: [],
            derived_documents: [],
            credit_notes: [],
            return_notes: [],
            document_chain: [],
            ancestors: [],
            current: {
              id: 'doc-1',
              type: 'invoice',
              document_number: 'INV-001',
              document_date: '2026-05-11',
              status: 'posted',
              total: '100',
              currency: 'EUR',
            },
            descendants: [],
          },
          meta: { timestamp: '2026-05-11T00:00:00Z' },
        },
      })
    }
    return Promise.resolve({ data: { data: [] } })
  })
  mockFetchTaxBreakdown.mockResolvedValue({
    subtotal: '100',
    total_tax_amount: '19',
    stamp_duty_amount: '0',
    total: '119',
    tax_details: [],
  })
  mockFetchPaymentHistory.mockResolvedValue({
    payment_allocations: [],
    total_paid: '0',
    balance_due: '119',
  })
  mockListCustomerHistorySearches.mockResolvedValue({ data: [] })
})

afterEach(() => {
  resetTenant()
})

describe('document tenant scope', () => {
  it('scopes document reads and hooks (.136, .154, .158, .162-.164, .197, .236-.237)', async () => {
    const queryClient = createClient()
    let observedClient: QueryClient | null = null

    render(
      <>
        <DocumentListPage documentType="invoice" />
        <ReturnNoteListPage />
        <DocumentTotals documentId="doc-1" documentType="invoice" currency="EUR" />
        <PaymentHistorySection documentId="doc-1" currency="EUR" />
        <RelatedDocumentsPanel documentId="doc-1" />
        <CacheProbe onClient={(client) => { observedClient = client }} />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    renderHook(() => useRelatedDocuments('doc-1'), { wrapper: wrapper(queryClient) })
    renderHook(() => useCustomerHistorySearches({ partner_id: 'partner-1' }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(observedClient?.getQueryData(['documents', 'invoice', '', 'all', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['return-notes', '', '', '', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['tax-breakdown', 'doc-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['payment-history', 'doc-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['related-documents', 'doc-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['documents', 'doc-1', 'related', 'tenant-A', 'company-1'])).toBeDefined()
      expect(observedClient?.getQueryData(['customer-history-searches', { partner_id: 'partner-1' }, 'tenant-A', 'company-1'])).toBeDefined()
    })
  })

  it('does not fetch document reads without tenant/company state', async () => {
    const queryClient = createClient()
    resetTenant()

    render(
      <>
        <DocumentTotals documentId="doc-1" documentType="invoice" currency="EUR" />
        <PaymentHistorySection documentId="doc-1" currency="EUR" />
        <RelatedDocumentsPanel documentId="doc-1" />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(mockFetchTaxBreakdown).not.toHaveBeenCalled()
    expect(mockFetchPaymentHistory).not.toHaveBeenCalled()
  })
})
