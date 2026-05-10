import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  progressionKeys,
  progressionModulesInvalidationPredicate,
  progressionProfileInvalidationPredicate,
  progressionRecommendationsInvalidationPredicate,
  useCompanyProfile,
  useMilestones,
} from '../hooks/useCompanyProgression'
import { useActivateModule, useModules } from '../hooks/useModuleReadiness'
import {
  useAcceptRecommendation,
  useDismissRecommendation,
  useRecommendations,
} from '../hooks/useRecommendations'

// ─── progressionApi mock — module-level boundary ─────────────────────────────

const mockGetProfile = vi.hoisted(() => vi.fn())
const mockGetMilestones = vi.hoisted(() => vi.fn())
const mockGetModules = vi.hoisted(() => vi.fn())
const mockActivateModule = vi.hoisted(() => vi.fn())
const mockGetRecommendations = vi.hoisted(() => vi.fn())
const mockAcceptRecommendation = vi.hoisted(() => vi.fn())
const mockDismissRecommendation = vi.hoisted(() => vi.fn())

vi.mock('../api/progressionApi', () => ({
  progressionApi: {
    getProfile: mockGetProfile,
    register: vi.fn(),
    getMilestones: mockGetMilestones,
    getModules: mockGetModules,
    activateModule: mockActivateModule,
    getRecommendations: mockGetRecommendations,
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

// ─── Helpers ──────────────────────────────────────────────────────────────────

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
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function progressionKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'progression')
}

beforeEach(() => {
  mockGetProfile.mockReset()
  mockGetProfile.mockResolvedValue({ id: 'p-1', current_stage: 'launch' })
  mockGetMilestones.mockReset()
  mockGetMilestones.mockResolvedValue([])
  mockGetModules.mockReset()
  mockGetModules.mockResolvedValue([])
  mockActivateModule.mockReset()
  mockActivateModule.mockResolvedValue({ id: 'mod-1', status: 'active' })
  mockGetRecommendations.mockReset()
  mockGetRecommendations.mockResolvedValue([])
  mockAcceptRecommendation.mockReset()
  mockAcceptRecommendation.mockResolvedValue({ id: 'r-1', status: 'accepted' })
  mockDismissRecommendation.mockReset()
  mockDismissRecommendation.mockResolvedValue({ id: 'r-1', status: 'dismissed' })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── progressionKeys factory contract ────────────────────────────────────────

describe('progressionKeys factory contract (wrap-at-callsite invariant)', () => {
  it('returns un-scoped structural prefixes', () => {
    expect(progressionKeys.all).toEqual(['progression'])
    expect(progressionKeys.profile()).toEqual(['progression', 'profile'])
    expect(progressionKeys.milestones()).toEqual(['progression', 'milestones'])
    expect(progressionKeys.modules()).toEqual(['progression', 'modules'])
    expect(progressionKeys.recommendations()).toEqual(['progression', 'recommendations'])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('progression invalidation predicates — namespace gating', () => {
  it('progressionProfileInvalidationPredicate matches only [progression, profile, ...] for the given t/c', () => {
    const pred = progressionProfileInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['progression', 'profile', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['progression', 'modules', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'recommendations', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'profile', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'profile', 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('progressionModulesInvalidationPredicate matches only [progression, modules, ...] for the given t/c', () => {
    const pred = progressionModulesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['progression', 'modules', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['progression', 'profile', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'recommendations', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('progressionRecommendationsInvalidationPredicate matches only [progression, recommendations, ...] for the given t/c', () => {
    const pred = progressionRecommendationsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['progression', 'recommendations', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['progression', 'modules', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'profile', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['progression', 'milestones', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .565, .566, .567, .570) ────────────────

describe('progression useQuery queryKey shapes', () => {
  function ProfileProbe() {
    useCompanyProfile()
    return null
  }
  function MilestonesProbe() {
    useMilestones()
    return null
  }
  function ModulesProbe() {
    useModules()
    return null
  }
  function RecommendationsProbe() {
    useRecommendations()
    return null
  }

  it('useCompanyProfile carries tenant + company at the suffix (.565)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ProfileProbe />, { queryClient })
    const keys = progressionKeysFromCache(queryClient)
    const profile = keys.find((k) => k[1] === 'profile')
    expect(profile).toEqual(['progression', 'profile', 'tenant-A', 'company-1'])
  })

  it('useMilestones carries tenant + company at the suffix (.566)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<MilestonesProbe />, { queryClient })
    const keys = progressionKeysFromCache(queryClient)
    const milestones = keys.find((k) => k[1] === 'milestones')
    expect(milestones).toEqual(['progression', 'milestones', 'tenant-A', 'company-1'])
  })

  it('useModules carries tenant + company at the suffix (.567)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ModulesProbe />, { queryClient })
    const keys = progressionKeysFromCache(queryClient)
    const modules = keys.find((k) => k[1] === 'modules')
    expect(modules).toEqual(['progression', 'modules', 'tenant-A', 'company-1'])
  })

  it('useRecommendations carries tenant + company at the suffix (.570)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<RecommendationsProbe />, { queryClient })
    const keys = progressionKeysFromCache(queryClient)
    const recommendations = keys.find((k) => k[1] === 'recommendations')
    expect(recommendations).toEqual(['progression', 'recommendations', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ProfileProbe />, { queryClient: cA })
    const kA = JSON.stringify(progressionKeysFromCache(cA))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ProfileProbe />, { queryClient: cB })
    const kB = JSON.stringify(progressionKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests for the 3 mutations (callsites .568, .569, .571, .572) ────
// useActivateModule → modules + profile cascade (.568, .569)
// useAcceptRecommendation → recommendations cascade (.571)
// useDismissRecommendation → recommendations cascade (.572)
// In all cases milestones is NOT cascaded (no mutation invalidates it).

describe('progression mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let profileCalls = 0
    let milestonesCalls = 0
    let modulesCalls = 0
    let recommendationsCalls = 0
    ;(globalThis as Record<string, unknown>)['__progressionCounters'] = {
      profile: () => profileCalls,
      milestones: () => milestonesCalls,
      modules: () => modulesCalls,
      recommendations: () => recommendationsCalls,
    }
    mockGetProfile.mockImplementation(async () => {
      profileCalls += 1
      return { id: `p-${profileCalls}`, current_stage: 'launch' }
    })
    mockGetMilestones.mockImplementation(async () => {
      milestonesCalls += 1
      return [{ id: `m-${milestonesCalls}` }]
    })
    mockGetModules.mockImplementation(async () => {
      modulesCalls += 1
      return [{ id: `mod-${modulesCalls}` }]
    })
    mockGetRecommendations.mockImplementation(async () => {
      recommendationsCalls += 1
      return [{ id: `r-${recommendationsCalls}` }]
    })
    useCompanyProfile()
    useMilestones()
    useModules()
    useRecommendations()
    const activate = useActivateModule()
    const accept = useAcceptRecommendation()
    const dismiss = useDismissRecommendation()
    ;(globalThis as Record<string, unknown>)['__progressionMutations'] = {
      activate,
      accept,
      dismiss,
    }
    return null
  }

  function getCounters() {
    return (globalThis as Record<string, unknown>)['__progressionCounters'] as {
      profile: () => number
      milestones: () => number
      modules: () => number
      recommendations: () => number
    }
  }
  function getMutations() {
    return (globalThis as Record<string, unknown>)['__progressionMutations'] as {
      activate: { mutateAsync: (input: unknown) => Promise<unknown> }
      accept: { mutateAsync: (input: unknown) => Promise<unknown> }
      dismiss: { mutateAsync: (input: unknown) => Promise<unknown> }
    }
  }

  it('useActivateModule refetches modules + profile; milestones + recommendations untouched (.568, .569)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().profile()).toBe(1)
      expect(getCounters().milestones()).toBe(1)
      expect(getCounters().modules()).toBe(1)
      expect(getCounters().recommendations()).toBe(1)
    })

    await getMutations().activate.mutateAsync('mod-1')

    expect(getCounters().modules()).toBe(2)
    expect(getCounters().profile()).toBe(2)
    // Sibling namespaces untouched.
    expect(getCounters().milestones()).toBe(1)
    expect(getCounters().recommendations()).toBe(1)
  })

  it('useAcceptRecommendation refetches recommendations only; profile/modules/milestones untouched (.571)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().recommendations()).toBe(1)
    })

    await getMutations().accept.mutateAsync('rec-1')

    expect(getCounters().recommendations()).toBe(2)
    expect(getCounters().profile()).toBe(1)
    expect(getCounters().modules()).toBe(1)
    expect(getCounters().milestones()).toBe(1)
  })

  it('useDismissRecommendation refetches recommendations only (.572)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().recommendations()).toBe(1)
    })

    await getMutations().dismiss.mutateAsync('rec-1')

    expect(getCounters().recommendations()).toBe(2)
    expect(getCounters().profile()).toBe(1)
    expect(getCounters().modules()).toBe(1)
    expect(getCounters().milestones()).toBe(1)
  })

  it('cross-tenant isolation: tenant-A activate does not refetch tenant-B progression queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().profile()).toBe(1)
      expect(getCounters().modules()).toBe(1)
    })

    const tenantBProfileKey = ['progression', 'profile', 'tenant-B', 'company-1']
    const tenantBModulesKey = ['progression', 'modules', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBProfileKey, { id: 'p-tenant-b' })
    queryClient.setQueryData(tenantBModulesKey, [{ id: 'mod-tenant-b' }])

    await getMutations().activate.mutateAsync('mod-1')

    expect(getCounters().profile()).toBe(2)
    expect(getCounters().modules()).toBe(2)
    const tBProfile = queryClient.getQueryCache().find({ queryKey: tenantBProfileKey, exact: true })
    expect(tBProfile?.state.data).toEqual({ id: 'p-tenant-b' })
    expect(tBProfile?.state.isInvalidated).toBe(false)
    const tBModules = queryClient.getQueryCache().find({ queryKey: tenantBModulesKey, exact: true })
    expect(tBModules?.state.data).toEqual([{ id: 'mod-tenant-b' }])
    expect(tBModules?.state.isInvalidated).toBe(false)
  })
})
