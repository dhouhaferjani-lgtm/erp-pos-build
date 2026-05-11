import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  VOUCHERS_KEY,
  vouchersInvalidationPredicate,
  useVoucher,
  useVouchers,
} from '../hooks/useVouchers'
import {
  useExtendExpiry,
  useIssueGoodwill,
  useTransferVoucher,
  useVoidVoucher,
} from '../hooks/useVoucherMutations'
import { useReservationSettings } from '../hooks/useReservationSettings'

// ─── voucherApi mock (mock at the API-function boundary; vouchers list uses
//     api.get directly to preserve pagination meta, so a @/lib/api boundary
//     mock would need to stub the axios client. Mocking the module is
//     simpler.) ────────────────────────────────────────────────────────────

const mockListVouchers = vi.hoisted(() => vi.fn())
const mockGetVoucher = vi.hoisted(() => vi.fn())
const mockGetReservationSettings = vi.hoisted(() => vi.fn())
const mockIssueGoodwill = vi.hoisted(() => vi.fn())
const mockVoidVoucher = vi.hoisted(() => vi.fn())
const mockTransferVoucher = vi.hoisted(() => vi.fn())
const mockExtendExpiry = vi.hoisted(() => vi.fn())

vi.mock('../api/voucherApi', () => ({
  listVouchers: mockListVouchers,
  getVoucher: mockGetVoucher,
  getVoucherReservationSettings: mockGetReservationSettings,
  issueGoodwill: mockIssueGoodwill,
  voidVoucher: mockVoidVoucher,
  transferVoucher: mockTransferVoucher,
  extendExpiry: mockExtendExpiry,
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
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

function voucherKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && (k[0] === 'vouchers' || k[0] === 'reservation-settings'))
}

beforeEach(() => {
  mockListVouchers.mockReset()
  mockListVouchers.mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
  })
  mockGetVoucher.mockReset()
  mockGetVoucher.mockResolvedValue({ id: 'v-1' })
  mockGetReservationSettings.mockReset()
  mockGetReservationSettings.mockResolvedValue({})
  mockIssueGoodwill.mockReset()
  mockIssueGoodwill.mockResolvedValue({ id: 'v-1' })
  mockVoidVoucher.mockReset()
  mockVoidVoucher.mockResolvedValue({ id: 'v-1' })
  mockTransferVoucher.mockReset()
  mockTransferVoucher.mockResolvedValue({ id: 'v-1' })
  mockExtendExpiry.mockReset()
  mockExtendExpiry.mockResolvedValue({ id: 'v-1' })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── VOUCHERS_KEY identifier contract ──────────────────────────────────────

describe('VOUCHERS_KEY identifier contract (wrap-at-callsite invariant)', () => {
  it('is the un-scoped [vouchers] structural prefix', () => {
    expect(VOUCHERS_KEY).toEqual(['vouchers'])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('vouchersInvalidationPredicate', () => {
  it('matches list + detail leaf keys for the given t/c', () => {
    const pred = vouchersInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['vouchers', {}, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['vouchers', { search: 'x' }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['vouchers', 'v-123', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects non-vouchers namespaces and wrong tenant/company', () => {
    const pred = vouchersInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['vouchers', {}, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['vouchers', {}, 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['promotions', {}, 'tenant-A', 'company-1'] })).toBe(false)
    // Reservation-settings uses a sibling namespace; predicate must NOT match.
    expect(pred({ queryKey: ['reservation-settings', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = vouchersInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['vouchers'] })).toBe(false)
    expect(pred({ queryKey: ['vouchers', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .771, .776, .777) ──────────────────────

describe('useVouchers / useVoucher / useReservationSettings queryKey shapes', () => {
  function ListProbe() {
    useVouchers()
    return null
  }
  function DetailProbe() {
    useVoucher('v-123')
    return null
  }
  function ReservationProbe() {
    useReservationSettings()
    return null
  }

  it('useVouchers queryKey carries tenant + company at the suffix (.776)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient })
    const keys = voucherKeysFromCache(queryClient)
    const list = keys.find((k) => k[0] === 'vouchers' && k.length === 4 && typeof k[1] === 'object')
    expect(list).toEqual(['vouchers', {}, 'tenant-A', 'company-1'])
  })

  it('useVoucher(id) queryKey carries tenant + company at the suffix (.777)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<DetailProbe />, { queryClient })
    const keys = voucherKeysFromCache(queryClient)
    const detail = keys.find((k) => k[0] === 'vouchers' && k[1] === 'v-123')
    expect(detail).toEqual(['vouchers', 'v-123', 'tenant-A', 'company-1'])
  })

  it('useReservationSettings queryKey is tenant-scoped under the reservation-settings namespace (.771)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ReservationProbe />, { queryClient })
    const keys = voucherKeysFromCache(queryClient)
    const settings = keys.find((k) => k[0] === 'reservation-settings')
    expect(settings).toEqual(['reservation-settings', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cA })
    const kA = JSON.stringify(voucherKeysFromCache(cA))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: cB })
    const kB = JSON.stringify(voucherKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests for the 4 mutations (callsites .772-.775) ─────────────────
// Per-call counters in the API-function mock so an always-false predicate
// would leave the counters at 1, deterministically failing the test.
// Each mutation's predicate scope is [vouchers, ...] only — reservation-settings
// is a sibling namespace and must NOT be invalidated by voucher mutations.

describe('voucher mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let listCalls = 0
    let detailCalls = 0
    let reservationCalls = 0
    ;(globalThis as Record<string, unknown>)['__voucherCounters'] = {
      list: () => listCalls,
      detail: () => detailCalls,
      reservation: () => reservationCalls,
    }
    mockListVouchers.mockImplementation(async () => {
      listCalls += 1
      return {
        data: [{ id: `v-${listCalls}` }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      }
    })
    mockGetVoucher.mockImplementation(async () => {
      detailCalls += 1
      return { id: `v-detail-${detailCalls}` }
    })
    mockGetReservationSettings.mockImplementation(async () => {
      reservationCalls += 1
      return { id: `r-${reservationCalls}` }
    })
    useVouchers()
    useVoucher('v-123')
    useReservationSettings()
    const goodwill = useIssueGoodwill()
    const voidV = useVoidVoucher()
    const transfer = useTransferVoucher()
    const extend = useExtendExpiry()
    ;(globalThis as Record<string, unknown>)['__voucherMutations'] = {
      goodwill,
      voidV,
      transfer,
      extend,
    }
    return null
  }

  function getCounters() {
    return (globalThis as Record<string, unknown>)['__voucherCounters'] as {
      list: () => number
      detail: () => number
      reservation: () => number
    }
  }
  function getMutations() {
    return (globalThis as Record<string, unknown>)['__voucherMutations'] as {
      goodwill: { mutateAsync: (input: unknown) => Promise<unknown> }
      voidV: { mutateAsync: (input: unknown) => Promise<unknown> }
      transfer: { mutateAsync: (input: unknown) => Promise<unknown> }
      extend: { mutateAsync: (input: unknown) => Promise<unknown> }
    }
  }

  it('useIssueGoodwill refetches BOTH list + detail; reservation-settings unchanged (.772)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
      expect(getCounters().reservation()).toBe(1)
    })

    await getMutations().goodwill.mutateAsync({ amount: '10', currency: 'EUR', reason: 'test' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    // Sibling namespace untouched — predicate is scoped to 'vouchers'.
    expect(getCounters().reservation()).toBe(1)
  })

  it('useVoidVoucher refetches BOTH list + detail; reservation-settings unchanged (.773)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().voidV.mutateAsync({ id: 'v-1', payload: { reason: 'fraud' } })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    expect(getCounters().reservation()).toBe(1)
  })

  it('useTransferVoucher refetches BOTH list + detail (.774)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().transfer.mutateAsync({ id: 'v-1', payload: { recipient_id: 'p-2' } })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('useExtendExpiry refetches BOTH list + detail (.775)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations().extend.mutateAsync({ id: 'v-1', payload: { new_expires_at: '2030-01-01' } })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('cross-tenant isolation: tenant-A mutation does not refetch tenant-B vouchers queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    const tenantBKey = ['vouchers', 'v-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { id: 'v-tenant-b' })

    await getMutations().goodwill.mutateAsync({ amount: '10', currency: 'EUR', reason: 'test' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ id: 'v-tenant-b' })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})
