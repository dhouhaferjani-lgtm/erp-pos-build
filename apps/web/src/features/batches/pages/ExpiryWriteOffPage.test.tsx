/**
 * B4b — Dedicated expiry write-off screen
 *
 * Test-gates (TDD, RED → GREEN):
 *   1. Selecting lots + entering quantities + confirming calls `groupedWriteOff`
 *      with the correct payload (location_id, one line per selected lot with the
 *      right batch_id UUID + quantity string, reason: 'expiry', non-empty
 *      idempotency_key).
 *   2. The confirm action is hidden without `can('batches.write-off')`.
 *   3. On success, the stock/expired-lots queries are invalidated.
 *   4. No raw user-facing strings (all via t()).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ExpiryWriteOffPage } from './ExpiryWriteOffPage'
import type { ExpiredBatch } from '../types'

// ── Permissions ─────────────────────────────────────────────────────────────
const mockHasPermission = vi.fn<(p: string) => boolean>()
vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

// ── Toast ────────────────────────────────────────────────────────────────────
const mockToastSuccess = vi.fn()
const mockToastError = vi.fn()
vi.mock('sonner', () => ({
  toast: {
    success: (msg: string): void => { mockToastSuccess(msg) },
    error: (msg: string): void => { mockToastError(msg) },
  },
}))

// ── i18n ─────────────────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

// ── Location ──────────────────────────────────────────────────────────────────
vi.mock('../../../hooks/useLocation', () => ({
  useLocation: () => ({ currentLocationId: 'loc-1' }),
}))
vi.mock('../../location/LocationSelector', () => ({
  LocationSelector: () => <div data-testid="location-selector" />,
}))

// ── Stores ────────────────────────────────────────────────────────────────────
vi.mock('../../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})
vi.mock('../../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

// ── Batches API client ────────────────────────────────────────────────────────
const mockGroupedWriteOff = vi.fn()
vi.mock('../api/batches', () => ({
  getExpiredBatches: vi.fn(),
  groupedWriteOff: (payload: unknown) => mockGroupedWriteOff(payload) as unknown,
}))

// ── TanStack Query ────────────────────────────────────────────────────────────
const capturedCallbacks: {
  onError?: (err: unknown) => void
  onSuccess?: () => void | Promise<void>
} = {}
const mockMutate = vi.fn()
const mockInvalidateQueries = vi.fn()

function makeBatch(overrides: Partial<ExpiredBatch>): ExpiredBatch {
  return {
    id: 1,
    uuid: 'batch-uuid-1',
    product_id: 'p-1',
    variant_id: null,
    batch_number: 'LOT-001',
    manufacturing_date: null,
    expiry_date: '2026-01-01',
    days_until_expiry: -175,
    is_active: true,
    is_expired: true,
    is_recalled: false,
    recall_reason: null,
    recalled_at: null,
    notes: null,
    expiry_status: 'EXPIRED',
    can_be_sold: false,
    total_quantity: 5,
    available_quantity: 5,
    created_at: null,
    updated_at: null,
    product: { id: 'p-1', name: 'Aspirin', sku: 'ASP-1' },
    batch_stock: [
      { location_id: 10, quantity: '5.0000', reserved_quantity: '0.0000', available_quantity: 5 },
    ],
    ...overrides,
  }
}

const batchA = makeBatch({ uuid: 'batch-uuid-1', available_quantity: 5, total_quantity: 5 })
const batchB = makeBatch({
  id: 2,
  uuid: 'batch-uuid-2',
  batch_number: 'LOT-002',
  product: { id: 'p-2', name: 'Ibuprofen', sku: 'IBU-1' },
  available_quantity: 12,
  total_quantity: 12,
  batch_stock: [
    { location_id: 10, quantity: '12.0000', reserved_quantity: '0.0000', available_quantity: 12 },
  ],
})

const mockQueryReturn = {
  data: [batchA, batchB] as ExpiredBatch[],
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockQueryReturn,
    useMutation: (opts: {
      mutationFn: unknown
      onSuccess?: () => void | Promise<void>
      onError?: (err: unknown) => void
    }) => {
      if (opts.onError) capturedCallbacks.onError = opts.onError
      if (opts.onSuccess) capturedCallbacks.onSuccess = opts.onSuccess
      return { mutate: mockMutate, isPending: false }
    },
    useQueryClient: () => ({ invalidateQueries: mockInvalidateQueries }),
  }
})

function setup() {
  return {
    user: userEvent.setup(),
    ...render(<ExpiryWriteOffPage />),
  }
}

describe('ExpiryWriteOffPage (B4b)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission.mockImplementation((p: string) => p === 'batches.write-off')
  })

  it('calls groupedWriteOff with the correct payload after selecting a lot and confirming', async () => {
    const { user } = setup()

    // Select the first lot (index 0 is the select-all header checkbox).
    const checkboxes = screen.getAllByRole('checkbox')
    await user.click(checkboxes[1])

    // Open the confirm dialog via the primary write-off action.
    await user.click(
      screen.getByRole('button', { name: 'expiryWriteOff.actions.writeOffSelected' }),
    )

    // Confirm.
    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    expect(mockMutate).toHaveBeenCalledWith({
      location_id: 'loc-1',
      lines: [{ batch_id: 'batch-uuid-1', quantity: '5' }],
      reason: 'expiry',
      idempotency_key: expect.stringMatching(/.+/) as unknown as string,
    })
  })

  it('reuses the same idempotency_key across retries of one submission', async () => {
    const { user } = setup()
    await user.click(screen.getAllByRole('checkbox')[1])
    await user.click(
      screen.getByRole('button', { name: 'expiryWriteOff.actions.writeOffSelected' }),
    )
    await user.click(screen.getByTestId('confirm-dialog-confirm'))
    // Simulate the mutation failing so the dialog stays open for a retry.
    act(() => {
      capturedCallbacks.onError?.(new Error('boom'))
    })
    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    expect(mockMutate).toHaveBeenCalledTimes(2)
    const firstKey = (mockMutate.mock.calls[0][0] as { idempotency_key: string }).idempotency_key
    const secondKey = (mockMutate.mock.calls[1][0] as { idempotency_key: string }).idempotency_key
    expect(firstKey).toBe(secondKey)
  })

  it('hides the write-off action without batches.write-off permission', () => {
    mockHasPermission.mockReturnValue(false)
    setup()
    expect(
      screen.queryByRole('button', { name: 'expiryWriteOff.actions.writeOffSelected' }),
    ).not.toBeInTheDocument()
  })

  it('invalidates queries on a successful write-off', async () => {
    setup()
    await act(async () => {
      await capturedCallbacks.onSuccess?.()
    })
    // One call per predicate: batches, stock-levels, stock-movements.
    expect(mockInvalidateQueries).toHaveBeenCalledTimes(3)
    // Spot-check the batches predicate (first call) so dropping or mis-scoping
    // that invalidation is caught by the test.
    const firstCall = mockInvalidateQueries.mock.calls[0][0] as {
      predicate: (q: { queryKey: readonly unknown[] }) => boolean
    }
    expect(firstCall.predicate({
      queryKey: ['batches', 'expired', 'loc-1', 'tenant-1', 'company-1'],
    })).toBe(true)
    expect(firstCall.predicate({
      queryKey: ['products', 'tenant-1', 'company-1'],
    })).toBe(false)
  })

  it('renders the title via an i18n key (no raw user-facing string)', () => {
    setup()
    expect(screen.getByText('expiryWriteOff.title')).toBeInTheDocument()
  })
})
