// Hook-integration test for useIncomeList (TD-013).
//
// Proves the /income list query RESOLVES (never stuck on "Chargement…") and
// that the resolved value is the full paginated envelope whose `.data` the
// list page reads for rows. Mirrors the expenses tenantScope hook probe.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import { useIncomeList } from '../useIncome'

const mockIncomeList = vi.hoisted(() => vi.fn())

vi.mock('../../api/incomeApi', () => ({
  incomeApi: {
    list: mockIncomeList,
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    post: vi.fn(),
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@t',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function makeWrapper(client: ReturnType<typeof createTestQueryClient>) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  mockIncomeList.mockReset()
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('useIncomeList', () => {
  it('resolves to the paginated envelope (not stuck loading) with rows under .data', async () => {
    const envelope = {
      data: [{ id: 'inc-1', document_number: 'INC-1', status: 'posted' }],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    }
    mockIncomeList.mockResolvedValue(envelope)

    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const { result } = renderHook(() => useIncomeList(), { wrapper: makeWrapper(client) })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })
    expect(result.current.isLoading).toBe(false)
    expect(result.current.data?.data).toEqual(envelope.data)
    expect(result.current.data?.data).toHaveLength(1)
  })

  it('carries tenant + company at the queryKey suffix', async () => {
    mockIncomeList.mockResolvedValue({ data: [], meta: {} })
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const { result } = renderHook(() => useIncomeList(), { wrapper: makeWrapper(client) })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })
    const key = client
      .getQueryCache()
      .getAll()
      .map((q) => q.queryKey as unknown[])
      .find((k) => Array.isArray(k) && k[0] === 'income')
    expect(key?.[key.length - 2]).toBe('tenant-A')
    expect(key?.[key.length - 1]).toBe('company-1')
  })
})
