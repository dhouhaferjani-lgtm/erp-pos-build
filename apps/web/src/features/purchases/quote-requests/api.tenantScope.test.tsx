import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useAwardQuoteRequest,
  useCreateQuoteRequestGroup,
  useQuoteRequest,
  useQuoteRequestGroup,
  useQuoteRequests,
  useReopenQuoteRequestGroup,
  useSendQuoteRequest,
  useUpdateQuoteRequest,
} from './api'

const mockApiGetMethod = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGetMethod,
    },
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPut: mockApiPut,
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
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
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
  mockApiGetMethod.mockResolvedValue({ data: { data: [] } })
  mockApiGet.mockImplementation((url: string) => {
    if (url.includes('/groups/')) return Promise.resolve({ group_id: 'group-1', siblings: [] })
    return Promise.resolve({ id: 'rfq-1', number: 'DP-1' })
  })
  mockApiPost.mockResolvedValue({ id: 'mutation-result', group_id: 'group-1', siblings: [] })
  mockApiPut.mockResolvedValue({ id: 'rfq-1', number: 'DP-1' })
})

afterEach(() => {
  act(() => {
    resetTenant()
  })
})

describe('quote request API hook tenant scope', () => {
  it('wraps read query keys with tenant/company suffixes and gates missing scope', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      list: useQuoteRequests({ status: 'confirmed' }),
      detail: useQuoteRequest('rfq-1'),
      group: useQuoteRequestGroup('group-1'),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.group.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['quote-requests', 'list', { status: 'confirmed' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['quote-requests', 'detail', 'rfq-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['quote-requests', 'group', 'group-1', 'tenant-A', 'company-1'])).toBeDefined()

    const listCalls = mockApiGetMethod.mock.calls.length
    const getCalls = mockApiGet.mock.calls.length
    act(() => {
      resetTenant()
    })
    renderHook(() => ({
      list: useQuoteRequests({}),
      detail: useQuoteRequest('rfq-2'),
      group: useQuoteRequestGroup('group-2'),
    }), { wrapper: wrapper(createClient()) })

    expect(mockApiGetMethod).toHaveBeenCalledTimes(listCalls)
    expect(mockApiGet).toHaveBeenCalledTimes(getCalls)
  })

  it('calls RFQ endpoints with string money and quantity payloads', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      createGroup: useCreateQuoteRequestGroup(),
      update: useUpdateQuoteRequest('rfq-1'),
      send: useSendQuoteRequest('rfq-1'),
      award: useAwardQuoteRequest('rfq-1', 'group-1'),
      reopen: useReopenQuoteRequestGroup('group-1'),
    }), { wrapper: wrapper(queryClient) })

    await act(async () => {
      await result.current.createGroup.mutateAsync({
        partner_ids: ['supplier-1', 'supplier-2'],
        validity_date: '2026-07-15',
        lines: [
          {
            product_id: 'product-1',
            variant_id: null,
            description: 'SPF50',
            quantity: '50.0000',
            unit_price: '8.200',
          },
        ],
      })
    })

    expect(mockApiPost).toHaveBeenCalledWith('/purchase-quote-requests', {
      partner_ids: ['supplier-1', 'supplier-2'],
      validity_date: '2026-07-15',
      lines: [
        {
          product_id: 'product-1',
          variant_id: null,
          description: 'SPF50',
          quantity: '50.0000',
          unit_price: '8.200',
        },
      ],
    })

    await act(async () => {
      await result.current.update.mutateAsync({
        supplier_reference: 'SUP-42',
        validity_date: '2026-07-31',
        lead_time_days: 7,
        lines: [
          {
            product_id: 'product-1',
            variant_id: null,
            description: 'SPF50',
            quantity: '50.0000',
            unit_price: '8.100',
          },
        ],
      })
    })
    expect(mockApiPut).toHaveBeenCalledWith('/purchase-quote-requests/rfq-1', expect.objectContaining({
      supplier_reference: 'SUP-42',
      lines: [expect.objectContaining({ quantity: '50.0000', unit_price: '8.100' })],
    }))

    await act(async () => {
      await result.current.send.mutateAsync()
      await result.current.award.mutateAsync()
      await result.current.reopen.mutateAsync()
    })

    expect(mockApiPost).toHaveBeenCalledWith('/purchase-quote-requests/rfq-1/send', {})
    expect(mockApiPost).toHaveBeenCalledWith('/purchase-quote-requests/rfq-1/convert-to-po', {})
    expect(mockApiPost).toHaveBeenCalledWith('/purchase-quote-requests/groups/group-1/reopen', {})
  })

  it('award invalidates the tenant-scoped list, detail, and group queries', async () => {
    const queryClient = createClient()
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
    const { result } = renderHook(() => useAwardQuoteRequest('rfq-1', 'group-1'), {
      wrapper: wrapper(queryClient),
    })

    await act(async () => {
      await result.current.mutateAsync()
    })

    expect(invalidateSpy).toHaveBeenCalledTimes(3)
    expect(mockApiPost).toHaveBeenCalledWith('/purchase-quote-requests/rfq-1/convert-to-po', {})
  })
})
