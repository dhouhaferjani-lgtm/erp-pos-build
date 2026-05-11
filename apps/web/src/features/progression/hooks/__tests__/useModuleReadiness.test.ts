import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useModules, useActivateModule } from '../useModuleReadiness'

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

const { mockActivateModule } = vi.hoisted(() => ({
  mockActivateModule: vi.fn().mockResolvedValue({
    id: 'mod1',
    name: 'Loyalty',
    status: 'active',
    readiness_percent: 100,
  }),
}))

vi.mock('../../api/progressionApi', () => ({
  progressionApi: {
    getProfile: vi.fn(),
    register: vi.fn(),
    getMilestones: vi.fn(),
    getModules: vi.fn().mockResolvedValue([
      { id: 'mod1', name: 'Loyalty', description: 'Customer loyalty', icon: '🎯', status: 'ready', readiness_percent: 95, stage: 'launch', discount_percent: 20, requirements: [] },
      { id: 'mod2', name: 'Analytics', description: 'Advanced analytics', icon: '📊', status: 'locked', readiness_percent: 30, stage: 'optimize', discount_percent: 0, requirements: ['Complete 100 sales'] },
    ]),
    activateModule: mockActivateModule,
    getRecommendations: vi.fn(),
    acceptRecommendation: vi.fn(),
    dismissRecommendation: vi.fn(),
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

describe('useModules', () => {
  it('fetches modules list', async () => {
    const { result } = renderHook(() => useModules(), { wrapper: createWrapper() })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(result.current.data).toHaveLength(2)
    expect(result.current.data?.[0].name).toBe('Loyalty')
  })
})

describe('useActivateModule', () => {
  it('calls activateModule API', async () => {
    const { result } = renderHook(() => useActivateModule(), { wrapper: createWrapper() })

    await act(async () => {
      result.current.mutate('mod1')
    })

    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(mockActivateModule).toHaveBeenCalledWith('mod1')
  })
})
