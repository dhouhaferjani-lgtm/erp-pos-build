import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useCompanyProfile, useMilestones, progressionKeys } from '../useCompanyProgression'

beforeEach(() => {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@t',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

vi.mock('../../api/progressionApi', () => ({
  progressionApi: {
    getProfile: vi.fn().mockResolvedValue({
      id: '1',
      tenant_id: 't1',
      vertical: 'retail',
      country: 'FR',
      current_stage: 'launch',
      stage_progress_percent: 45,
      total_milestones: 10,
      completed_milestones: 4,
    }),
    getMilestones: vi.fn().mockResolvedValue([
      { id: 'm1', name: 'First Sale', description: 'Complete your first sale', status: 'completed', progress_percent: 100, stage: 'launch' },
      { id: 'm2', name: 'Add Products', description: 'Add 10 products', status: 'in_progress', progress_percent: 60, stage: 'launch' },
    ]),
    register: vi.fn(),
    getModules: vi.fn(),
    activateModule: vi.fn(),
    getRecommendations: vi.fn(),
    acceptRecommendation: vi.fn(),
    dismissRecommendation: vi.fn(),
  },
}))

function createWrapper() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children)
  }
}

describe('progressionKeys', () => {
  it('builds correct query key hierarchy', () => {
    expect(progressionKeys.all).toEqual(['progression'])
    expect(progressionKeys.profile()).toEqual(['progression', 'profile'])
    expect(progressionKeys.milestones()).toEqual(['progression', 'milestones'])
    expect(progressionKeys.modules()).toEqual(['progression', 'modules'])
    expect(progressionKeys.recommendations()).toEqual(['progression', 'recommendations'])
  })
})

describe('useCompanyProfile', () => {
  it('fetches company profile', async () => {
    const { result } = renderHook(() => useCompanyProfile(), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(result.current.data?.current_stage).toBe('launch')
    expect(result.current.data?.completed_milestones).toBe(4)
  })
})

describe('useMilestones', () => {
  it('fetches milestones list', async () => {
    const { result } = renderHook(() => useMilestones(), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(result.current.data).toHaveLength(2)
    expect(result.current.data?.[0].name).toBe('First Sale')
  })
})
