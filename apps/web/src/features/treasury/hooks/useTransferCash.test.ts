import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { createElement, type ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useTransferCash } from './useTransferCash'

const mockPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, post: mockPost } }
})

const payload = {
  from_repository_id: '11111111-1111-4111-8111-111111111111',
  to_repository_id: '22222222-2222-4222-8222-222222222222',
  amount: '10.000',
  notes: 'Till sweep',
  transfer_group_id: '33333333-3333-4333-8333-333333333333',
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children)
  }
}

describe('useTransferCash', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
    mockPost.mockResolvedValue({
      data: {
        message: 'created',
        data: {
          transfer_group_id: payload.transfer_group_id,
          journal_entry_id: null,
          idempotent_replay: false,
          out: { movement_id: 'out-1', balance_after: '90.000', repository_id: payload.from_repository_id },
          in: { movement_id: 'in-1', balance_after: '10.000', repository_id: payload.to_repository_id },
        },
      },
    })
  })

  it('posts the transfer and invalidates every affected scoped and movement key', async () => {
    const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
    const { result } = renderHook(() => useTransferCash(), { wrapper: wrapper(queryClient) })

    await result.current.mutateAsync(payload)

    expect(mockPost).toHaveBeenCalledWith('/payment-repositories/transfers', payload)
    await waitFor(() => {
      expect(invalidateSpy.mock.calls.map(([filter]) => filter?.queryKey)).toEqual([
        ['payment-repositories'],
        ['payment-repository', payload.from_repository_id],
        ['payment-repository', payload.to_repository_id],
        ['payment-repository-transactions', payload.from_repository_id],
        ['payment-repository-transactions', payload.to_repository_id],
        ['treasury-cash-position'],
        ['repository-movements', payload.from_repository_id],
        ['repository-movements', payload.to_repository_id],
      ])
    })
  })
})
