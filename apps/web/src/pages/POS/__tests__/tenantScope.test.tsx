import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { useQuery } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

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
    companies: [
      {
        id: companyId,
        name: 'Co',
        legalName: 'Co',
        taxId: null,
        countryCode: 'FR',
        currency: 'EUR',
        locale: 'fr',
        timezone: 'Europe/Paris',
      },
    ],
    isLoading: false,
  })
}

beforeEach(() => {
  // reset
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── useQuery shape probes ────────────────────────────────────────────────────

describe('POS useQuery shapes carry tenant_id + company_id', () => {
  function ShiftQueryProbe() {
    // Mirrors the live POSLayout shift query shape.
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['pos', 'shift', 'TERM-1']),
      queryFn: async () => null,
      enabled: !!tenantId && !!companyId,
    })
    return null
  }

  function ShiftBalanceQueryProbe() {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['pos', 'shift-balance', 'sid-1']),
      queryFn: async () => null,
      enabled: !!tenantId && !!companyId,
    })
    return null
  }

  function PaymentMethodsQueryProbe() {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['pos', 'payment-methods']),
      queryFn: async () => null,
      enabled: !!tenantId && !!companyId,
    })
    return null
  }

  function PaymentReposQueryProbe() {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['pos', 'payment-repositories']),
      queryFn: async () => null,
      enabled: !!tenantId && !!companyId,
    })
    return null
  }

  function LocationsQueryProbe() {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['locations']),
      queryFn: async () => [],
      enabled: !!tenantId && !!companyId,
    })
    return null
  }

  function findKeysWithFirst(client: ReturnType<typeof createTestQueryClient>, first: string): unknown[][] {
    return client
      .getQueryCache()
      .getAll()
      .map((q) => q.queryKey as unknown[])
      .filter((k) => Array.isArray(k) && k[0] === first)
  }

  it("['pos', 'shift', terminalCode] queryKey carries tenant + company", () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ShiftQueryProbe />, { queryClient })
    const keys = findKeysWithFirst(queryClient, 'pos').filter((k) => k[1] === 'shift')
    expect(keys.length).toBe(1)
    expect(keys[0]).toEqual(['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'])
  })

  it("['pos', 'shift-balance', shiftId] queryKey carries tenant + company", () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ShiftBalanceQueryProbe />, { queryClient })
    const keys = findKeysWithFirst(queryClient, 'pos').filter((k) => k[1] === 'shift-balance')
    expect(keys[0]).toEqual(['pos', 'shift-balance', 'sid-1', 'tenant-A', 'company-1'])
  })

  it("['pos', 'payment-methods'] and ['pos', 'payment-repositories'] queryKeys carry tenant + company", () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <>
        <PaymentMethodsQueryProbe />
        <PaymentReposQueryProbe />
      </>,
      { queryClient },
    )
    const keys = findKeysWithFirst(queryClient, 'pos')
    const methods = keys.find((k) => k[1] === 'payment-methods')
    const repos = keys.find((k) => k[1] === 'payment-repositories')
    expect(methods).toEqual(['pos', 'payment-methods', 'tenant-A', 'company-1'])
    expect(repos).toEqual(['pos', 'payment-repositories', 'tenant-A', 'company-1'])
  })

  it("['locations'] queryKey carries tenant + company", () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<LocationsQueryProbe />, { queryClient })
    const keys = findKeysWithFirst(queryClient, 'locations')
    expect(keys[0]).toEqual(['locations', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants (cross-tenant cache isolation)', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ShiftQueryProbe />, { queryClient: cA })
    const kA = JSON.stringify(cA.getQueryCache().getAll().map((q) => q.queryKey))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ShiftQueryProbe />, { queryClient: cB })
    const kB = JSON.stringify(cB.getQueryCache().getAll().map((q) => q.queryKey))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})
