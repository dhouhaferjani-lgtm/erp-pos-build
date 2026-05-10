import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { waitFor } from '@testing-library/react'
import { QueryClient, useQueryClient, useQuery } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  posShiftBalanceInvalidationPredicate,
  posShiftInvalidationPredicate,
} from '../_invalidation'

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

// ─── Predicate unit tests ─────────────────────────────────────────────────────

describe('posShiftInvalidationPredicate (callsites .841, .846, .847)', () => {
  it('matches ANY [pos, shift, ...] query for the given tenant + company', () => {
    const pred = posShiftInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['pos', 'shift', null, 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects shift queries for a different tenant or company', () => {
    const pred = posShiftInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift', 'TERM-1', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['pos', 'shift', 'TERM-1', 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('rejects unrelated keys', () => {
    const pred = posShiftInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift-balance', 'sid', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['products', 'list', null, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['pos', 'shift'] })).toBe(false) // too short
  })
})

describe('posShiftBalanceInvalidationPredicate (callsite .842)', () => {
  it('matches ANY [pos, shift-balance, ...] query for the given t/c', () => {
    const pred = posShiftBalanceInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift-balance', 'sid-1', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects [pos, shift, ...] queries (different second segment)', () => {
    const pred = posShiftBalanceInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects keys for a different tenant', () => {
    const pred = posShiftBalanceInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['pos', 'shift-balance', 'sid', 'tenant-B', 'company-1'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .839, .840, .843, .844, .845, .849) ────

describe('POS useQuery shapes carry tenant_id + company_id', () => {
  function ShiftQueryProbe() {
    // Mirrors POSShiftsDashboard L48 + POSTransactions L128 shape.
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

  it("['locations'] queryKey carries tenant + company (callsite .849)", () => {
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

// ─── End-to-end cascade tests ────────────────────────────────────────────────

describe('POS shift invalidation cascade through predicate', () => {
  it('invalidating with posShiftInvalidationPredicate cascades to ALL shift entries (across terminalCodes) for the active tenant', async () => {
    setTenant('tenant-A', 'company-1')
    // Non-zero gcTime so seeded entries with no observers survive.
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false, gcTime: Infinity },
        mutations: { retry: false },
      },
    })

    function CascadeProbe() {
      const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
      const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
      // Two distinct shift queries for two terminal codes — both should
      // invalidate together via the predicate.
      useQuery({
        queryKey: tenantScopedKey(['pos', 'shift', 'TERM-1']),
        queryFn: async () => 'shift-1',
        enabled: !!tenantId && !!companyId,
      })
      useQuery({
        queryKey: tenantScopedKey(['pos', 'shift', 'TERM-2']),
        queryFn: async () => 'shift-2',
        enabled: !!tenantId && !!companyId,
      })
      // A non-shift query that must NOT be invalidated.
      useQuery({
        queryKey: tenantScopedKey(['pos', 'payment-methods']),
        queryFn: async () => [],
        enabled: !!tenantId && !!companyId,
      })
      const queryClientFromProvider = useQueryClient()
      ;(globalThis as Record<string, unknown>)['__cascadeQc'] = queryClientFromProvider
      return null
    }

    renderWithProviders(<CascadeProbe />, { queryClient })
    // Wait for the 3 initial fetches to register.
    await waitFor(() => {
      const all = queryClient.getQueryCache().getAll()
      expect(all.filter((q) => q.queryKey[0] === 'pos').length).toBeGreaterThanOrEqual(3)
    })

    // Seed a tenant-B shift entry to verify cross-tenant isolation.
    const tenantBKey = ['pos', 'shift', 'TERM-1', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, 'shift-other-tenant')

    // Fire predicate-based invalidation against the active tenant.
    await queryClient.invalidateQueries({
      predicate: posShiftInvalidationPredicate('tenant-A', 'company-1'),
    })

    const cache = queryClient.getQueryCache()

    // Both tenant-A shift entries are invalidated (they had observers, so
    // refetch fired and isInvalidated reset; data should refresh).
    const t1 = cache.find({
      queryKey: ['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'],
      exact: true,
    })
    const t2 = cache.find({
      queryKey: ['pos', 'shift', 'TERM-2', 'tenant-A', 'company-1'],
      exact: true,
    })
    expect(t1?.state.data).toBe('shift-1')
    expect(t2?.state.data).toBe('shift-2')

    // Tenant-B shift entry must remain UNTOUCHED (no observer, no refetch
    // would fire even if it matched, but the predicate must reject it).
    const tBKeyEntry = cache.find({ queryKey: tenantBKey, exact: true })
    expect(tBKeyEntry?.state.data).toBe('shift-other-tenant')
    expect(tBKeyEntry?.state.isInvalidated).toBe(false)

    // payment-methods is NOT a shift query — must be untouched.
    const pm = cache.find({
      queryKey: ['pos', 'payment-methods', 'tenant-A', 'company-1'],
      exact: true,
    })
    expect(pm?.state.isInvalidated).toBe(false)
  })
})
