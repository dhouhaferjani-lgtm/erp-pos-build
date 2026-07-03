import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useAcceptEnrichment,
  useEnrichmentResult,
  useEnrichmentResults,
  useRejectEnrichment,
} from '../enrichmentQueries'

const mockGetEnrichmentResults = vi.hoisted(() => vi.fn())
const mockGetEnrichmentResult = vi.hoisted(() => vi.fn())
const mockAcceptEnrichmentResult = vi.hoisted(() => vi.fn())
const mockRejectEnrichmentResult = vi.hoisted(() => vi.fn())

vi.mock('../enrichmentApi', () => ({
  acceptEnrichmentResult: mockAcceptEnrichmentResult,
  bulkAcceptEnrichmentResults: vi.fn(),
  getEnrichmentResult: mockGetEnrichmentResult,
  getEnrichmentResults: mockGetEnrichmentResults,
  rejectEnrichmentResult: mockRejectEnrichmentResult,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
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

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetEnrichmentResults.mockResolvedValue({ data: [] })
  mockGetEnrichmentResult.mockResolvedValue({ id: 'enrich-1' })
  mockAcceptEnrichmentResult.mockResolvedValue(undefined)
  mockRejectEnrichmentResult.mockResolvedValue(undefined)
})

afterEach(() => {
  resetTenant()
})

describe('enrichment query tenant scope', () => {
  it('wraps enrichment read keys and gates missing tenant/company (.347-.348)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      detail: useEnrichmentResult('enrich-1'),
      list: useEnrichmentResults({ status: 'pending' }),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.list.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['enrichment-results', 'list', { status: 'pending' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['enrichment-results', 'detail', 'enrich-1', 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const listCalls = mockGetEnrichmentResults.mock.calls.length
    renderHook(() => useEnrichmentResults(), { wrapper: wrapper(createClient()) })
    expect(mockGetEnrichmentResults).toHaveBeenCalledTimes(listCalls)
  })

  it('invalidates only active-tenant enrichment keys (.349)', async () => {
    let listCalls = 0
    let detailCalls = 0
    mockGetEnrichmentResults.mockImplementation(async () => ({ data: [`list-${++listCalls}`] }))
    mockGetEnrichmentResult.mockImplementation(async () => ({ id: `detail-${++detailCalls}` }))

    const queryClient = createClient()
    queryClient.setQueryData(['enrichment-results', 'list', undefined, 'tenant-B', 'company-1'], { marker: 'tenant-B-list' })

    const { result } = renderHook(() => ({
      accept: useAcceptEnrichment(),
      detail: useEnrichmentResult('enrich-1'),
      list: useEnrichmentResults(),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(listCalls).toBe(1)
      expect(detailCalls).toBe(1)
    })

    await act(async () => {
      await result.current.accept.mutateAsync({ id: 'enrich-1', acceptedFields: ['name'] })
    })

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(2)
    })
    expect(queryClient.getQueryData(['enrichment-results', 'list', undefined, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-list' })
  })

  it('passes structured reject reason and notes through the mutation', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => useRejectEnrichment(), { wrapper: wrapper(queryClient) })

    await act(async () => {
      await result.current.mutateAsync({
        id: 'enrich-1',
        notes: 'Specifications do not match the product.',
        reason: 'wrong_product',
      })
    })

    expect(mockRejectEnrichmentResult).toHaveBeenCalledWith(
      'enrich-1',
      'wrong_product',
      'Specifications do not match the product.',
    )
  })
})
