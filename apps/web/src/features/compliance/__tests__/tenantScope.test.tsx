import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  fraudAlertStatisticsInvalidationPredicate,
  fraudAlertsInvalidationPredicate,
  fraudSettingsInvalidationPredicate,
} from '../_invalidation'
import {
  AssignAlertModal,
  DismissAlertModal,
  ResolveAlertModal,
} from '../components/FraudAlertActionModals'
import { FraudAlertsPage } from '../pages/FraudAlertsPage'

// ─── api mock ───────────────────────────────────────────────────────────────

const mockGetFraudAlerts = vi.hoisted(() => vi.fn())
const mockGetFraudAlertStatistics = vi.hoisted(() => vi.fn())
const mockGetUsersWithAdminRole = vi.hoisted(() => vi.fn())
const mockAssignFraudAlert = vi.hoisted(() => vi.fn())
const mockDismissFraudAlert = vi.hoisted(() => vi.fn())
const mockResolveFraudAlert = vi.hoisted(() => vi.fn())

vi.mock('../api/fraudApi', () => ({
  getFraudAlerts: mockGetFraudAlerts,
  getFraudAlertStatistics: mockGetFraudAlertStatistics,
  getUsersWithAdminRole: mockGetUsersWithAdminRole,
  assignFraudAlert: mockAssignFraudAlert,
  dismissFraudAlert: mockDismissFraudAlert,
  resolveFraudAlert: mockResolveFraudAlert,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// FraudAlertsPage imports a sibling components index that re-exports a few
// modals; mock it to avoid heavy transitive imports in this test scope.
vi.mock('../components', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('../components/FraudAlertActionModals')
  return {
    ...actual,
    FraudAlertDetailModal: () => null,
  }
})

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

function complianceKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter(
      (k) =>
        Array.isArray(k) &&
        (k[0] === 'fraud-alerts' ||
          k[0] === 'fraud-alert-statistics' ||
          k[0] === 'fraud-settings' ||
          k[0] === 'users'),
    )
}

beforeEach(() => {
  mockGetFraudAlerts.mockReset()
  mockGetFraudAlerts.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
  mockGetFraudAlertStatistics.mockReset()
  mockGetFraudAlertStatistics.mockResolvedValue({ data: { total: 0, by_severity: {}, by_status: {} } })
  mockGetUsersWithAdminRole.mockReset()
  mockGetUsersWithAdminRole.mockResolvedValue({ data: [] })
  mockAssignFraudAlert.mockReset()
  mockAssignFraudAlert.mockResolvedValue({ data: {} })
  mockDismissFraudAlert.mockReset()
  mockDismissFraudAlert.mockResolvedValue({ data: {} })
  mockResolveFraudAlert.mockReset()
  mockResolveFraudAlert.mockResolvedValue({ data: {} })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('fraudAlertsInvalidationPredicate', () => {
  it('matches list keys for the given t/c', () => {
    const pred = fraudAlertsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-alerts', {}, 1, 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces and wrong t/c', () => {
    const pred = fraudAlertsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-alert-statistics', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['fraud-settings', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['users', 'admin-role', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['fraud-alerts', {}, 1, 'tenant-B', 'company-1'] })).toBe(false)
  })
})

describe('fraudAlertStatisticsInvalidationPredicate', () => {
  it('matches statistics keys for the given t/c', () => {
    const pred = fraudAlertStatisticsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-alert-statistics', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces (including users)', () => {
    const pred = fraudAlertStatisticsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-alerts', {}, 1, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['fraud-settings', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['users', 'admin-role', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

describe('fraudSettingsInvalidationPredicate', () => {
  it('matches settings keys for the given t/c', () => {
    const pred = fraudSettingsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-settings', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling namespaces (including users)', () => {
    const pred = fraudSettingsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['fraud-alerts', {}, 1, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['fraud-alert-statistics', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['users', 'admin-role', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

// ─── useQuery shape probes ────────────────────────────────────────────────────

describe('FraudAlertsPage useQuery shapes', () => {
  it('alerts + statistics queryKeys carry tenant + company at the suffix (.115, .116)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<FraudAlertsPage />, { queryClient })
    await waitFor(() => {
      expect(mockGetFraudAlerts).toHaveBeenCalled()
      expect(mockGetFraudAlertStatistics).toHaveBeenCalled()
    })
    const keys = complianceKeysFromCache(queryClient)
    const alerts = keys.find((k) => k[0] === 'fraud-alerts')
    const stats = keys.find((k) => k[0] === 'fraud-alert-statistics')
    expect(alerts?.[alerts.length - 2]).toBe('tenant-A')
    expect(alerts?.[alerts.length - 1]).toBe('company-1')
    expect(stats).toEqual(['fraud-alert-statistics', 'tenant-A', 'company-1'])
  })
})

describe('AssignAlertModal users useQuery shape (.108)', () => {
  it('users admin-role queryKey carries tenant + company at the suffix', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <AssignAlertModal alert={{ id: 'a-1' } as never} onClose={() => {}} />,
      { queryClient },
    )
    await waitFor(() => {
      expect(mockGetUsersWithAdminRole).toHaveBeenCalled()
    })
    const keys = complianceKeysFromCache(queryClient)
    const users = keys.find((k) => k[0] === 'users')
    expect(users).toEqual(['users', 'admin-role', 'tenant-A', 'company-1'])
  })
})

// ─── Cascade tests for the 3 modal mutations (.109-.114) ─────────────────────
// Each modal mutation cascades BOTH fraud-alerts + fraud-alert-statistics
// predicates via Promise.all. Per-call counters on alerts + statistics + users
// queries; users counter must stay at 1 (sibling namespace).

type ModalCtor = React.ComponentType<{ alert: never; onClose: () => void }>

function CascadeProbe({ Modal }: { Modal: ModalCtor }) {
  let alertsCalls = 0
  let statsCalls = 0
  let usersCalls = 0
  ;(globalThis as Record<string, unknown>)['__complianceCounters'] = {
    alerts: () => alertsCalls,
    stats: () => statsCalls,
    users: () => usersCalls,
  }
  mockGetFraudAlerts.mockImplementation(async () => {
    alertsCalls += 1
    return { data: [{ id: `a-${alertsCalls}` }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }
  })
  mockGetFraudAlertStatistics.mockImplementation(async () => {
    statsCalls += 1
    return { data: { total: statsCalls, by_severity: {}, by_status: {} } }
  })
  mockGetUsersWithAdminRole.mockImplementation(async () => {
    usersCalls += 1
    return { data: [{ id: `u-${usersCalls}`, name: 'U', email: 'u@u' }] }
  })
  return (
    <>
      <FraudAlertsPage />
      <Modal alert={{ id: 'a-1' } as never} onClose={() => {}} />
    </>
  )
}

function getCounters() {
  return (globalThis as Record<string, unknown>)['__complianceCounters'] as {
    alerts: () => number
    stats: () => number
    users: () => number
  }
}

describe('compliance modal cascades — fetch-count signals', () => {
  it.each([
    ['AssignAlertModal (.109, .110)', AssignAlertModal as unknown as ModalCtor, true],
    ['DismissAlertModal (.111, .112)', DismissAlertModal as unknown as ModalCtor, false],
    ['ResolveAlertModal (.113, .114)', ResolveAlertModal as unknown as ModalCtor, false],
  ])('%s cascades fraud-alerts + fraud-alert-statistics; users untouched', async (_label, Modal, hasUsersQuery) => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe Modal={Modal} />, { queryClient })

    await waitFor(() => {
      expect(getCounters().alerts()).toBe(1)
      expect(getCounters().stats()).toBe(1)
    })

    const usersBefore = getCounters().users()
    if (hasUsersQuery) {
      // AssignAlertModal mounts the users useQuery; counter should be 1.
      await waitFor(() => {
        expect(getCounters().users()).toBe(1)
      })
      // Wait for the option element from the loaded users to render, then
      // pick it via the labelled <select>.
      await waitFor(() => {
        expect(
          screen.getByLabelText('compliance:fraudAlerts.detail.assignedTo'),
        ).toBeInTheDocument()
      })
      fireEvent.change(
        screen.getByLabelText('compliance:fraudAlerts.detail.assignedTo'),
        { target: { value: 'u-1' } },
      )
    } else {
      // Dismiss + Resolve modals require notes (form is disabled otherwise).
      const noteLabel = Modal === DismissAlertModal
        ? 'compliance:fraudAlerts.modals.dismiss.notesLabel'
        : 'compliance:fraudAlerts.modals.resolve.notesLabel'
      fireEvent.change(screen.getByLabelText(noteLabel), { target: { value: 'note' } })
    }

    // Drive the actual modal mutation by clicking the production submit button
    // so onSuccess fires its predicate-based invalidates exactly as the user
    // would. Removing either invalidate from the modal's onSuccess Promise.all
    // would leave the corresponding counter at 1 and fail this test.
    const submitName = Modal === AssignAlertModal
      ? 'compliance:fraudAlerts.actions.assign'
      : Modal === DismissAlertModal
        ? 'compliance:fraudAlerts.actions.dismiss'
        : 'compliance:fraudAlerts.actions.resolve'
    fireEvent.click(screen.getByRole('button', { name: submitName }))

    await waitFor(() => {
      expect(getCounters().alerts()).toBe(2)
      expect(getCounters().stats()).toBe(2)
    })
    // users sibling untouched — counter unchanged from before invalidate.
    expect(getCounters().users()).toBe(hasUsersQuery ? 1 : usersBefore)
  })

  it('cross-tenant isolation: tenant-A predicate-based invalidate does not refetch tenant-B fraud-alerts', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBKey = ['fraud-alerts', {}, 1, 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { data: [{ id: 'a-tenant-b' }] })

    await Promise.all([
      queryClient.invalidateQueries({
        predicate: fraudAlertsInvalidationPredicate('tenant-A', 'company-1'),
      }),
      queryClient.invalidateQueries({
        predicate: fraudAlertStatisticsInvalidationPredicate('tenant-A', 'company-1'),
      }),
    ])

    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ data: [{ id: 'a-tenant-b' }] })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })

  it('cross-tenant data isolation: tenant-A FraudAlertsPage results do not contain tenant-B entries', async () => {
    // Stronger cross-tenant assertion (Codex B12 round-1 BLOCK fix): the
    // previous test only proved that tenant-B's CACHE ENTRY survives a
    // tenant-A invalidate. Codex flagged this as insufficient — it doesn't
    // prove tenant-A's QUERY RESULTS are free of tenant-B data. Here we
    // pre-seed tenant-B fraud-alerts data, render FraudAlertsPage under
    // tenant-A, and assert the tenant-A query result is the empty
    // tenant-A response from the mock — NOT the seeded tenant-B payload.
    const queryClient = createTestQueryClient()

    // Seed tenant-B fraud-alerts cache entry BEFORE rendering anything.
    const tenantBKey = ['fraud-alerts', {}, 1, 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, {
      data: [{ id: 'leaked-tenant-b-alert', tenant_id: 'tenant-B' }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })

    setTenant('tenant-A', 'company-1')
    renderWithProviders(<FraudAlertsPage />, { queryClient })

    await waitFor(() => {
      expect(mockGetFraudAlerts).toHaveBeenCalled()
    })

    // tenant-A queryKey carries 'tenant-A' suffix — different cache slot
    // from tenantBKey. tenant-A query must hold ONLY the mock response
    // for tenant-A (empty array), not the seeded tenant-B payload.
    const tenantAKey = ['fraud-alerts', {}, 1, 'tenant-A', 'company-1']
    const tAQuery = queryClient.getQueryCache().find({ queryKey: tenantAKey, exact: true })
    expect(tAQuery).toBeDefined()
    const tAData = tAQuery?.state.data as { data?: Array<{ id: string }> } | undefined
    expect(tAData?.data ?? []).toEqual([])
    // No tenant-B entry leaked into tenant-A's data array.
    const tAIds = (tAData?.data ?? []).map((entry) => entry.id)
    expect(tAIds).not.toContain('leaked-tenant-b-alert')
  })
})
