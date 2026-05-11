import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useCloseWithTolerance } from '../useCloseWithTolerance'

const mockCloseInvoiceWithTolerance = vi.hoisted(() => vi.fn())

vi.mock('../../api/closeWithTolerance', () => ({
  closeInvoiceWithTolerance: mockCloseInvoiceWithTolerance,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
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
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function useProbe(queryKey: readonly unknown[], queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  return useQuery({ queryKey: tenantScopedKey(queryKey), queryFn, enabled: tenantId !== null && companyId !== null })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockCloseInvoiceWithTolerance.mockResolvedValue({ invoice_id: 'invoice-1' })
})

afterEach(() => {
  resetTenant()
})

describe('close with tolerance tenant scope', () => {
  it('invalidates invoice detail, documents, and payments only for active tenant (.218-.220)', async () => {
    const queryClient = createClient()
    let invoiceCalls = 0
    let documentsCalls = 0
    let paymentsCalls = 0

    const invoiceQuery = vi.fn(async () => ({ id: `invoice-${++invoiceCalls}` }))
    const documentsQuery = vi.fn(async () => [{ id: `document-${++documentsCalls}` }])
    const paymentsQuery = vi.fn(async () => [{ id: `payment-${++paymentsCalls}` }])

    queryClient.setQueryData(['document', 'invoice', 'invoice-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-invoice' })
    queryClient.setQueryData(['documents', 'tenant-B', 'company-1'], { marker: 'tenant-B-documents' })
    queryClient.setQueryData(['payments', 'tenant-B', 'company-1'], { marker: 'tenant-B-payments' })

    const { result: reads } = renderHook(() => ({
      documents: useProbe(['documents'], documentsQuery),
      invoice: useProbe(['document', 'invoice', 'invoice-1'], invoiceQuery),
      payments: useProbe(['payments'], paymentsQuery),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(reads.current.documents.isSuccess).toBe(true)
      expect(reads.current.invoice.isSuccess).toBe(true)
      expect(reads.current.payments.isSuccess).toBe(true)
      expect(invoiceCalls).toBe(1)
      expect(documentsCalls).toBe(1)
      expect(paymentsCalls).toBe(1)
    })

    const { result: mutation } = renderHook(() => useCloseWithTolerance({ invoiceId: 'invoice-1' }), { wrapper: wrapper(queryClient) })
    await act(async () => {
      await mutation.current.mutateAsync()
    })

    await waitFor(() => {
      expect(invoiceCalls).toBe(2)
      expect(documentsCalls).toBe(2)
      expect(paymentsCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['document', 'invoice', 'invoice-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-invoice' })
    expect(queryClient.getQueryData(['documents', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-documents' })
    expect(queryClient.getQueryData(['payments', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-payments' })
  })
})
