import { QueryClient } from '@tanstack/react-query'
import { act, waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { renderWithProviders } from '@/test/renderWithProviders'
import type { TestCompanyConfig } from '@/test/fixtures/companyConfig'

import { useCartRecommendations } from '../useCartRecommendations'
import { useContactProfile } from '../useContactProfile'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockSmartPromptsApi = vi.hoisted(() => ({
  getRecommendations: vi.fn(),
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPatch: mockApiPatch,
  }
})

vi.mock('../../api/smartPromptsApi', () => ({
  smartPromptsApi: mockSmartPromptsApi,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
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
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
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

function cacheKeys(client: QueryClient): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const smartPromptsConfig: TestCompanyConfig = {
  vertical: 'parapharmacy',
  default_modules: ['Identity', 'POS'],
  enabled_extras: [],
  all_enabled_modules: ['Identity', 'POS'],
  currency: 'TND',
  locale: 'en',
  country_code: 'TN',
  smart_prompts_enabled: true,
  smart_prompts_variant: 'inline',
  line_designation_override_enabled: false,
  purchase_bonus_enabled: false,
  platform_import_enrichment_available: false,
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockSmartPromptsApi.getRecommendations.mockResolvedValue({
    recommendations: [],
    context: 'cart',
    generated_at: '2026-05-11T10:00:00Z',
  })
  mockApiGet.mockResolvedValue({
    id: 'contact-1',
    profile_metadata: {
      skin_type: 'dry',
      updated_at: '2026-05-11T10:00:00Z',
    },
  })
  mockApiPatch.mockResolvedValue({})
})

afterEach(() => {
  vi.useRealTimers()
  resetTenant()
})

describe('POS smart prompts tenant scope', () => {
  function RecommendationsProbe() {
    useCartRecommendations(['product-2', 'product-1'], 'contact-1')
    return null
  }

  function ContactProfileProbe() {
    const fetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__contactProfileCounters'] = {
      profile: () => fetchesRef.current,
    }
    mockApiGet.mockImplementation(async () => {
      fetchesRef.current += 1
      return {
        id: 'contact-1',
        profile_metadata: {
          skin_type: 'dry',
          updated_at: '2026-05-11T10:00:00Z',
        },
      }
    })
    const result = useContactProfile('contact-1')
    ;(globalThis as Record<string, unknown>)['__contactProfileMutation'] = result.updateProfileMetadata
    return null
  }

  function contactCounters() {
    return (globalThis as Record<string, unknown>)['__contactProfileCounters'] as {
      profile: () => number
    }
  }

  function updateContactProfile(metadata: Record<string, string>) {
    ;((globalThis as Record<string, unknown>)['__contactProfileMutation'] as (input: Record<string, string>) => void)(metadata);
  }

  it('scopes cart recommendation and contact-profile query keys (.541-.542)', async () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(
      <>
        <RecommendationsProbe />
        <ContactProfileProbe />
      </>,
      { queryClient, companyConfig: smartPromptsConfig },
    )

    await waitFor(() => {
      expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
        ['smart-prompts', 'recommendations', ['product-1', 'product-2'], null, 'contact-1', 'tenant-A', 'company-1'],
        ['contact-profile', 'contact-1', 'tenant-A', 'company-1'],
      ]))
    })
  })

  it('does not fetch without tenant/company state', async () => {
    vi.useFakeTimers()
    resetTenant()

    renderWithProviders(
      <>
        <RecommendationsProbe />
        <ContactProfileProbe />
      </>,
      { companyConfig: smartPromptsConfig },
    )

    await act(async () => {
      vi.advanceTimersByTime(300)
    })

    expect(mockSmartPromptsApi.getRecommendations).not.toHaveBeenCalled()
    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('refetches current-tenant contact profile and preserves tenant-B cache (.543)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<ContactProfileProbe />, { queryClient })

    await waitFor(() => {
      expect(contactCounters().profile()).toBe(1)
    })
    queryClient.setQueryData(['contact-profile', 'contact-1', 'tenant-B', 'company-1'], {
      marker: 'tenant-B',
    })

    updateContactProfile({ skin_type: 'oily' })

    await waitFor(() => {
      expect(contactCounters().profile()).toBe(2)
    })
    expect(queryClient.getQueryData(['contact-profile', 'contact-1', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B',
    })
  })
})
