import { QueryClient, useQuery } from '@tanstack/react-query'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { POSLayout } from './POSLayout'
import { ShiftOperationsMenu } from './ShiftOperationsMenu'
import type { CurrentShift, ShiftBalance } from '../api/shiftApi'

const mockShiftApi = vi.hoisted(() => ({
  getCurrentShift: vi.fn(),
  getShiftBalance: vi.fn(),
  generateXReport: vi.fn(),
}))

vi.mock('../api/shiftApi', async () => {
  const actual = await vi.importActual<typeof import('../api/shiftApi')>('../api/shiftApi')
  return {
    ...actual,
    ...mockShiftApi,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    format: (value: number) => String(value),
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
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

function cacheKeys(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const shift: CurrentShift = {
  id: 'shift-1',
  terminal_id: 'terminal-1',
  shift_number: 7,
  status: 'OPEN',
  opening_cash: '100.000',
  opened_at: '2026-05-11T10:00:00Z',
  user: {
    id: 'user-1',
    name: 'Test User',
  },
}

const balance: ShiftBalance = {
  opening_cash: '100.000',
  total_sales: '25.000',
  total_refunds: '0.000',
  total_deposits: '0.000',
  total_payouts: '0.000',
  expected_cash: '125.000',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockShiftApi.getCurrentShift.mockResolvedValue(shift)
  mockShiftApi.getShiftBalance.mockResolvedValue(balance)
  mockShiftApi.generateXReport.mockResolvedValue({ id: 'report-1' })
})

afterEach(() => {
  resetTenant()
})

describe('POS layout shift query tenant scope', () => {
  it('scopes current-shift and shift-balance query keys (.524-.525)', async () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(
      <POSLayout onExitPOS={vi.fn()} terminalCode="TERM-1">
        <div>POS content</div>
      </POSLayout>,
      { queryClient },
    )

    await waitFor(() => {
      expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
        ['pos', 'shift', 'TERM-1', 'tenant-A', 'company-1'],
        ['pos', 'shift-balance', 'shift-1', 'tenant-A', 'company-1'],
      ]))
    })
  })

  it('does not fetch shift data without tenant/company state', () => {
    resetTenant()

    renderWithProviders(
      <POSLayout onExitPOS={vi.fn()} terminalCode="TERM-1">
        <div>POS content</div>
      </POSLayout>,
    )

    expect(mockShiftApi.getCurrentShift).not.toHaveBeenCalled()
    expect(mockShiftApi.getShiftBalance).not.toHaveBeenCalled()
  })
})

describe('shift operations invalidation tenant scope', () => {
  function ShiftCacheProbe() {
    const shiftFetchesRef = useRef(0)
    const balanceFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__shiftCounters'] = {
      shift: () => shiftFetchesRef.current,
      balance: () => balanceFetchesRef.current,
    }
    useQuery({
      queryKey: tenantScopedKey(['pos', 'shift', 'TERM-1']),
      queryFn: async () => {
        shiftFetchesRef.current += 1
        return shift
      },
    })
    useQuery({
      queryKey: tenantScopedKey(['pos', 'shift-balance', 'shift-1']),
      queryFn: async () => {
        balanceFetchesRef.current += 1
        return balance
      },
    })

    return (
      <ShiftOperationsMenu
        shift={shift}
        balance={balance}
        terminalCode="TERM-1"
        isOpen={true}
        onClose={vi.fn()}
        onOpenCashDeposit={vi.fn()}
        onOpenCashPayout={vi.fn()}
      />
    )
  }

  function shiftCounters() {
    return (globalThis as Record<string, unknown>)['__shiftCounters'] as {
      shift: () => number
      balance: () => number
    }
  }

  it('refetches current-tenant shift caches and preserves tenant-B cache (.526-.527)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<ShiftCacheProbe />, { queryClient })

    await waitFor(() => {
      expect(shiftCounters().shift()).toBe(1)
      expect(shiftCounters().balance()).toBe(1)
    })

    queryClient.setQueryData(['pos', 'shift', 'TERM-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-shift' })
    queryClient.setQueryData(['pos', 'shift-balance', 'shift-1', 'tenant-B', 'company-1'], {
      marker: 'tenant-B-balance',
    })

    fireEvent.click(screen.getByText('common:pos.generateXReport'))

    await waitFor(() => {
      expect(shiftCounters().shift()).toBe(2)
      expect(shiftCounters().balance()).toBe(2)
    })
    expect(queryClient.getQueryData(['pos', 'shift', 'TERM-1', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-shift',
    })
    expect(queryClient.getQueryData(['pos', 'shift-balance', 'shift-1', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-balance',
    })
  })
})
