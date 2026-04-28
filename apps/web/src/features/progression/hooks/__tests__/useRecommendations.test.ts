import { describe, it, expect, vi } from 'vitest'
import { renderHook, waitFor, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { useRecommendations, useAcceptRecommendation, useDismissRecommendation } from '../useRecommendations'

const { mockAcceptRecommendation, mockDismissRecommendation } = vi.hoisted(() => ({
  mockAcceptRecommendation: vi.fn().mockResolvedValue({
    id: 'rec1',
    title: 'Add more products',
    status: 'accepted',
  }),
  mockDismissRecommendation: vi.fn().mockResolvedValue({
    id: 'rec2',
    title: 'Set up loyalty',
    status: 'dismissed',
  }),
}))

vi.mock('../../api/progressionApi', () => ({
  progressionApi: {
    getProfile: vi.fn(),
    register: vi.fn(),
    getMilestones: vi.fn(),
    getModules: vi.fn(),
    activateModule: vi.fn(),
    getRecommendations: vi.fn().mockResolvedValue([
      { id: 'rec1', title: 'Add more products', description: 'Add at least 50 products', priority: 'high', action_label: 'Go to catalog', action_route: '/catalog', status: 'pending' },
      { id: 'rec2', title: 'Set up loyalty', description: 'Create a loyalty program', priority: 'medium', action_label: '', action_route: '', status: 'pending' },
    ]),
    acceptRecommendation: mockAcceptRecommendation,
    dismissRecommendation: mockDismissRecommendation,
  },
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('i18next', () => ({
  default: { t: (key: string) => key },
}))

function createWrapper() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children)
  }
}

describe('useRecommendations', () => {
  it('fetches recommendations list', async () => {
    const { result } = renderHook(() => useRecommendations(), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(result.current.data).toHaveLength(2)
    expect(result.current.data?.[0].title).toBe('Add more products')
  })
})

describe('useAcceptRecommendation', () => {
  it('calls acceptRecommendation API', async () => {
    const { result } = renderHook(() => useAcceptRecommendation(), { wrapper: createWrapper() })

    await act(async () => {
      result.current.mutate('rec1')
    })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(mockAcceptRecommendation).toHaveBeenCalledWith('rec1')
  })
})

describe('useDismissRecommendation', () => {
  it('calls dismissRecommendation API', async () => {
    const { result } = renderHook(() => useDismissRecommendation(), { wrapper: createWrapper() })

    await act(async () => {
      result.current.mutate('rec2')
    })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(mockDismissRecommendation).toHaveBeenCalledWith('rec2')
  })
})
