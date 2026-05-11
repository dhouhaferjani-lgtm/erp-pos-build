import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useAdditionalCosts,
  useCreateAdditionalCost,
  useDeleteAdditionalCost,
  useLandedCostBreakdown,
  useUpdateAdditionalCost,
} from '../useAdditionalCosts'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('@/lib/api', () => ({
  api: {
    get: mockApiGet,
  },
  apiDelete: mockApiDelete,
  apiPatch: mockApiPatch,
  apiPost: mockApiPost,
  getErrorMessage: (error: unknown) => error instanceof Error ? error.message : 'Unknown error',
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
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
    companies: [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function useDocumentProbe(queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['document', 'purchase_order', 'document-1']),
    queryFn,
    enabled: tenantId !== null && companyId !== null,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({
    data: {
      data: {
        document_id: 'document-1',
        total_additional_costs: 10,
        allocations: [],
      },
    },
  })
  mockApiPost.mockResolvedValue({ data: { id: 'cost-1' } })
  mockApiPatch.mockResolvedValue({ data: { id: 'cost-1' } })
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  resetTenant()
})

describe('additional costs hooks tenant scope', () => {
  it('wraps additional cost read query keys with the active tenant and company (.174, .181)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      costs: useAdditionalCosts('document-1'),
      landedCost: useLandedCostBreakdown('document-1'),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.costs.isSuccess).toBe(true)
      expect(result.current.landedCost.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['additional-costs', 'document-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['landed-cost-breakdown', 'document-1', 'tenant-A', 'company-1'])).toBeDefined()
  })

  it('does not fetch additional cost reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      costs: useAdditionalCosts('document-1'),
      landedCost: useLandedCostBreakdown('document-1'),
    }), { wrapper })

    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('bounds additional cost mutation invalidation to the active tenant cache (.175-.180)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let costsCalls = 0
    let documentCalls = 0
    let landedCostCalls = 0

    mockApiGet.mockImplementation(async (url: string) => {
      if (url.includes('/additional-costs')) {
        costsCalls += 1
        return { data: { data: [{ id: `cost-${costsCalls}` }] } }
      }
      landedCostCalls += 1
      return {
        data: {
          data: {
            document_id: 'document-1',
            total_additional_costs: landedCostCalls,
            allocations: [],
          },
        },
      }
    })
    const documentQueryFn = vi.fn(async () => {
      documentCalls += 1
      return { id: `document-${documentCalls}` }
    })

    queryClient.setQueryData(['additional-costs', 'document-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-costs' })
    queryClient.setQueryData(['document', 'purchase_order', 'document-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-document' })
    queryClient.setQueryData(['landed-cost-breakdown', 'document-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-landed-cost' })

    const { result: reads } = renderHook(() => ({
      costs: useAdditionalCosts('document-1'),
      document: useDocumentProbe(documentQueryFn),
      landedCost: useLandedCostBreakdown('document-1'),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.costs.isSuccess).toBe(true)
      expect(reads.current.document.isSuccess).toBe(true)
      expect(reads.current.landedCost.isSuccess).toBe(true)
      expect(costsCalls).toBe(1)
      expect(documentCalls).toBe(1)
      expect(landedCostCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      createCost: useCreateAdditionalCost('document-1'),
      deleteCost: useDeleteAdditionalCost('document-1'),
      updateCost: useUpdateAdditionalCost('document-1'),
    }), { wrapper })

    await act(async () => {
      await mutations.current.createCost.mutateAsync({
        amount: 10,
        cost_type: 'shipping',
        description: 'Shipping',
      })
    })
    await waitFor(() => {
      expect(costsCalls).toBe(2)
      expect(documentCalls).toBe(2)
      expect(landedCostCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.updateCost.mutateAsync({
        costId: 'cost-1',
        data: { amount: 11 },
      })
    })
    await waitFor(() => {
      expect(costsCalls).toBe(3)
      expect(documentCalls).toBe(3)
      expect(landedCostCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.deleteCost.mutateAsync('cost-1')
    })
    await waitFor(() => {
      expect(costsCalls).toBe(4)
      expect(documentCalls).toBe(4)
      expect(landedCostCalls).toBe(4)
    })

    expect(queryClient.getQueryData(['additional-costs', 'document-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-costs' })
    expect(queryClient.getQueryData(['document', 'purchase_order', 'document-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-document' })
    expect(queryClient.getQueryData(['landed-cost-breakdown', 'document-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-landed-cost' })
  })
})
