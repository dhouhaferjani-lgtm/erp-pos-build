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
import { StockMovementsPage } from './StockMovementsPage'

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
vi.mock('../location/LocationSelector', () => ({
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
// We capture the onError callback so test-gate 4 (409 toast) can fire it.
const capturedCallbacks: { onError?: (err: unknown) => void } = {}
const mockMutate = vi.fn()
const mockInvalidateQueries = vi.fn()

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  reason: string | null
  quantity: string
  quantity_before: string
  quantity_after: string
  reference: string
  notes: string | null
  user_id: string
  user_name: string | null
  reverses_movement_id: string | null
  is_reversed: boolean
  created_at: string
}

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
    quantity_before: '10.0000',
    quantity_after: '5.0000',
    reference: 'REF-001',
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

const mockQueryReturn = {
  data: {
    data: [writeOffMovement, alreadyReversedMovement, normalIssueMovement],
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
      onSuccess?: unknown
      onError?: (err: unknown) => void
    }) => {
      if (opts.onError) capturedCallbacks.onError = opts.onError
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
    // After the dialog opens, there are two buttons with the same label:
    // the row action button + the ConfirmDialog confirm button.
    // The dialog confirm button is the last one in the DOM (portal renders
    // at document.body, after the table).
    const reverseButtons = screen.getAllByRole('button', {
      name: 'movements.actions.reverse',
    })
    const confirmBtn = reverseButtons[reverseButtons.length - 1]
    await user.click(confirmBtn)
    expect(mockMutate).toHaveBeenCalledWith('wo-1')
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
