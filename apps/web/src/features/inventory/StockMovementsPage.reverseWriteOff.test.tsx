/**
 * C3 — Reverse posted write-off action
 *
 * Test-gates (TDD, RED → GREEN):
 *   1. Reverse button visible for unreversed write-off with permission
 *   2. Reverse button absent for already-reversed write-off
 *   3. Clicking Reverse → confirm dialog → mutation called with correct id
 *   4. 409 response → error toast (already reversed)
 *   5. No raw user-facing strings (all via t())
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StockMovementsPage, type StockMovement } from './StockMovementsPage'

// ── Permissions ─────────────────────────────────────────────────────────────
const mockHasPermission = vi.fn<(p: string) => boolean>()
vi.mock('../../hooks/usePermissions', () => ({
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

// ── Router ───────────────────────────────────────────────────────────────────
vi.mock('react-router-dom', () => ({
  Link: ({
    to,
    children,
    ...props
  }: {
    to: string
    children: React.ReactNode
    className?: string
  }) => (
    <a href={to} {...props}>
      {children}
    </a>
  ),
}))

// ── Location / Stores ────────────────────────────────────────────────────────
vi.mock('../../hooks/useLocation', () => ({
  useLocation: () => ({ currentLocationId: null }),
}))
vi.mock('../locations/LocationSelector', () => ({
  LocationSelector: () => <div data-testid="location-selector" />,
}))

vi.mock('../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) =>
    selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) =>
    selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

// ── Reversal API client ───────────────────────────────────────────────────────
const mockReverseWriteOff = vi.fn()
vi.mock('../batches/api/batches', () => ({
  reverseWriteOff: (id: string) => mockReverseWriteOff(id) as unknown,
}))

// ── TanStack Query ────────────────────────────────────────────────────────────
// We capture the onError / onSuccess callbacks so tests can fire them directly.
const capturedCallbacks: {
  onError?: (err: unknown) => void
  onSuccess?: () => void | Promise<void>
} = {}
const mockMutate = vi.fn()
const mockInvalidateQueries = vi.fn()

// Fixtures are typed by the page's own exported row type (gate r2, N7). The old
// local copy omitted `quantity_decimals`, so getQuantityDecimals() ran on
// undefined here while the suite stayed green.
function makeMovement(overrides: Partial<StockMovement>): StockMovement {
  return {
    id: 'id-1',
    product_id: 'p-1',
    product_name: 'Widget',
    location_id: 'loc-1',
    location_name: 'Main',
    movement_type: 'issue',
    reason: null,
    quantity: '-5.0000',
    quantity_decimals: 3,
    quantity_before: '10.0000',
    quantity_after: '5.0000',
    reference: 'REF-001',
    reference_type: null,
    reference_id: null,
    source_document_id: null,
    source_document_type: null,
    notes: null,
    user_id: 'u-1',
    user_name: 'Alice',
    reverses_movement_id: null,
    is_reversed: false,
    created_at: '2026-06-25T10:00:00Z',
    ...overrides,
  }
}

const writeOffMovement = makeMovement({
  id: 'wo-1',
  product_name: 'ExpiredWidget',
  movement_type: 'issue',
  reason: 'write_off',
  is_reversed: false,
})

const alreadyReversedMovement = makeMovement({
  id: 'wo-2',
  product_name: 'AlreadyReversed',
  movement_type: 'issue',
  reason: 'write_off',
  is_reversed: true,
})

const normalIssueMovement = makeMovement({
  id: 'iss-1',
  product_name: 'NormalIssue',
  movement_type: 'issue',
  reason: null,
  is_reversed: false,
})

const expiryWriteOffMovement = makeMovement({
  id: 'wo-expiry-1',
  product_name: 'ExpiryWidget',
  movement_type: 'issue',
  reason: 'expiry',
  is_reversed: false,
})

const damageWriteOffMovement = makeMovement({
  id: 'wo-damage-1',
  product_name: 'DamageWidget',
  movement_type: 'issue',
  reason: 'damage',
  is_reversed: false,
})

// A write-off REVERSAL is a RECEIPT that inherits the original's reason, so it
// looks like a write-off by reason alone and lands in the Write-Offs tab. The
// backend refuses to reverse it (ReverseWriteOffService: "is itself a reversal
// and cannot be reversed"), so the button must not be offered (gate r1, B2).
const reversalReceiptMovement = makeMovement({
  id: 'wo-rev-receipt-1',
  product_name: 'ReversalReceipt',
  movement_type: 'receipt',
  reason: 'write_off',
  reverses_movement_id: 'wo-1',
  is_reversed: false,
})

// A STOCK ADJUSTMENT line may carry reason_code `damage`/`write_off` on a
// non-batch-tracked product (StockAdjustmentDocumentService), and posts as
// movement_type='adjustment' with that reason and reverses_movement_id=null.
// The reason-only Write-Offs tab surfaces it, but ReverseWriteOffService refuses
// anything that is not an ISSUE ("Only write-off issue movements can be
// reversed"), so the control must not be offered (gate r2, F1).
const adjustmentDamageMovement = makeMovement({
  id: 'adj-damage-1',
  product_name: 'AdjustedDamagedWidget',
  movement_type: 'adjustment',
  reason: 'damage',
  reverses_movement_id: null,
  is_reversed: false,
})

const alreadyReversedExpiryMovement = makeMovement({
  id: 'wo-expiry-rev',
  product_name: 'ReversedExpiryWidget',
  movement_type: 'issue',
  reason: 'expiry',
  is_reversed: true,
})

// The bounded endpoint ALWAYS emits `meta` now (gate r1, B1) — the fixture has
// to carry the same six fields the server does.
const mockQueryReturn = {
  data: {
    data: [writeOffMovement, alreadyReversedMovement, normalIssueMovement],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 3,
      from: 1,
      to: 3,
    },
  },
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function setup() {
  return {
    user: userEvent.setup(),
    ...render(<StockMovementsPage />),
  }
}

// ── Tests ─────────────────────────────────────────────────────────────────────
describe('StockMovementsPage — reverse write-off action (C3)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission.mockImplementation((p: string) => p === 'batches.write-off')
  })

  it('shows a Reverse button for an unreversed write-off when user has batches.write-off permission', () => {
    setup()
    // The Reverse button should appear for the unreversed write-off row
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons.length).toBeGreaterThanOrEqual(1)
  })

  it('does NOT show a Reverse button for an already-reversed write-off', () => {
    setup()
    // There is only one unreversed write-off row (wo-1), so there should be
    // exactly one Reverse button — wo-2 (already reversed) must not have one.
    const reverseButtons = screen.queryAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    // wo-1 has a button; wo-2 must not — so count is exactly 1
    expect(reverseButtons).toHaveLength(1)
  })

  it('does NOT show a Reverse button for a non-write-off issue movement', () => {
    setup()
    // NormalIssue has movement_type=issue but reason=null → no Reverse button for it
    // The one button that exists belongs to wo-1 only
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons).toHaveLength(1)
  })

  it('does NOT show a Reverse button when user lacks batches.write-off permission', () => {
    mockHasPermission.mockReturnValue(false)
    setup()
    expect(
      screen.queryByRole('button', { name: 'movements.actions.reverse' }),
    ).not.toBeInTheDocument()
  })

  it('opens a ConfirmDialog when the Reverse button is clicked', async () => {
    const { user } = setup()
    const reverseBtn = screen.getByRole('button', {
      name: 'movements.actions.reverse',
    })
    await user.click(reverseBtn)
    // ConfirmDialog renders the title as an h3
    expect(
      screen.getByText('movements.actions.reverseConfirmTitle'),
    ).toBeInTheDocument()
  })

  it('calls mutate with the correct movement id when the confirm dialog is submitted', async () => {
    const { user } = setup()
    await user.click(
      screen.getByRole('button', { name: 'movements.actions.reverse' }),
    )
    // ConfirmDialog exposes data-testid="confirm-dialog-confirm" on its confirm
    // button, giving a portal-order-independent stable selector.
    const confirmBtn = screen.getByTestId('confirm-dialog-confirm')
    await user.click(confirmBtn)
    expect(mockMutate).toHaveBeenCalledWith('wo-1')
  })

  it('invalidates stock-movements, stock-levels, and batches queries on successful reversal', async () => {
    setup()
    await act(async () => {
      await capturedCallbacks.onSuccess?.()
    })
    // onSuccess calls invalidateQueries three times: stock-movements, stock-levels, batches
    expect(mockInvalidateQueries).toHaveBeenCalledTimes(3)
  })

  it('shows an error toast when the onError callback is invoked with a 409', () => {
    setup()
    // Simulate the 409 error from onError (captured when useMutation was called)
    const mockError = {
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          error: {
            code: 'WRITE_OFF_ALREADY_REVERSED',
            message: 'Already reversed',
          },
        },
      },
    }
    act(() => {
      capturedCallbacks.onError?.(mockError)
    })
    expect(mockToastError).toHaveBeenCalledWith(
      'movements.actions.reverseAlreadyReversed',
    )
  })

  it('shows a generic error toast for non-409 errors', () => {
    setup()
    act(() => {
      capturedCallbacks.onError?.(new Error('Network error'))
    })
    expect(mockToastError).toHaveBeenCalledWith(
      'movements.actions.reverseFailed',
    )
  })

  it('uses only i18n keys for user-facing strings (no raw text in Reverse button)', () => {
    setup()
    const reverseBtn = screen.getByRole('button', {
      name: 'movements.actions.reverse',
    })
    // The accessible name is the t() key, not a hardcoded string
    expect(reverseBtn.textContent).toBe('movements.actions.reverse')
  })
})

describe('StockMovementsPage — reverse write-off action for expiry/damage reasons (C3 extended)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission.mockImplementation((p: string) => p === 'batches.write-off')
  })

  it('shows a Reverse button for an unreversed expiry write-off', () => {
    mockQueryReturn.data.data = [expiryWriteOffMovement, normalIssueMovement]
    setup()
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons).toHaveLength(1)
  })

  it('shows a Reverse button for an unreversed damage write-off', () => {
    mockQueryReturn.data.data = [damageWriteOffMovement, normalIssueMovement]
    setup()
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons).toHaveLength(1)
  })

  it('does NOT show a Reverse button for an already-reversed expiry write-off', () => {
    mockQueryReturn.data.data = [alreadyReversedExpiryMovement, normalIssueMovement]
    setup()
    expect(
      screen.queryByRole('button', { name: 'movements.actions.reverse' }),
    ).not.toBeInTheDocument()
  })

  it('shows Reverse for expiry but not for already-reversed expiry in same list', () => {
    mockQueryReturn.data.data = [expiryWriteOffMovement, alreadyReversedExpiryMovement, normalIssueMovement]
    setup()
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    // Only the unreversed expiry row gets a button
    expect(reverseButtons).toHaveLength(1)
  })

  it('calls mutate with the correct id when reversing an expiry write-off', async () => {
    mockQueryReturn.data.data = [expiryWriteOffMovement]
    const { user } = setup()
    await user.click(
      screen.getByRole('button', { name: 'movements.actions.reverse' }),
    )
    const confirmBtn = screen.getByTestId('confirm-dialog-confirm')
    await user.click(confirmBtn)
    expect(mockMutate).toHaveBeenCalledWith('wo-expiry-1')
  })

  it('does NOT show a Reverse button for a write-off REVERSAL receipt', () => {
    mockQueryReturn.data.data = [reversalReceiptMovement]
    setup()
    expect(
      screen.queryByRole('button', { name: 'movements.actions.reverse' }),
    ).not.toBeInTheDocument()
  })

  it('shows Reverse for the genuine write-off but not for its reversal receipt', () => {
    mockQueryReturn.data.data = [writeOffMovement, reversalReceiptMovement]
    setup()
    const reverseButtons = screen.queryAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons).toHaveLength(1)
  })

  it('does NOT show a Reverse button for an adjustment-sourced damage write-off', () => {
    mockQueryReturn.data.data = [adjustmentDamageMovement]
    setup()
    expect(
      screen.queryByRole('button', { name: 'movements.actions.reverse' }),
    ).not.toBeInTheDocument()
  })

  it('shows Reverse for the issue write-off but not for the adjustment-sourced one', () => {
    mockQueryReturn.data.data = [writeOffMovement, adjustmentDamageMovement]
    setup()
    const reverseButtons = screen.queryAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    expect(reverseButtons).toHaveLength(1)
  })

  afterEach(() => {
    // Restore the default fixture so other describe blocks are unaffected
    mockQueryReturn.data.data = [writeOffMovement, alreadyReversedMovement, normalIssueMovement]
  })
})
