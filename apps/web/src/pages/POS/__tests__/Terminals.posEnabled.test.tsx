import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { TerminalsPage } from '../Terminals'

/**
 * Owner ruling B-3 (2026-08-23), gate r1 / P1-2.
 *
 * The server now refuses `POST /api/v1/pos/terminals` at a location whose POS
 * is switched off (422 `LOCATION_POS_DISABLED`). This page is that endpoint's
 * ONLY admin client, and before this lane it (a) offered every location in the
 * create form and (b) swallowed the error object entirely, so the operator got
 * a generic "error creating terminal" with no cause and no next step — on the
 * exact flow the launch tenant runs (register, add a second shop, which is
 * created POS-disabled by deliberate design, then try to put a till there).
 *
 * These cases pin the offer side and the refusal side.
 */

const mockGetLocations = vi.hoisted(() => vi.fn())
const mockCreateMutateAsync = vi.hoisted(() => vi.fn())
const mockToastError = vi.hoisted(() => vi.fn())
const mockToastSuccess = vi.hoisted(() => vi.fn())

vi.mock('@/features/locations/api/locations', () => ({
  getLocations: mockGetLocations,
}))

vi.mock('sonner', () => ({
  toast: { error: mockToastError, success: mockToastSuccess },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/pos">{children}</a>,
}))

const noopMutation = { mutateAsync: vi.fn(), isPending: false }

/** Mutable per-case terminal list, read by the `useTerminals` mock below. */
const terminalState = vi.hoisted(() => ({ current: [] as Record<string, unknown>[] }))

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({ data: terminalState.current, isLoading: false }),
  useCreateTerminal: () => ({ mutateAsync: mockCreateMutateAsync, isPending: false }),
  useUpdateTerminal: () => noopMutation,
  useArchiveTerminal: () => noopMutation,
  useDeleteTerminal: () => noopMutation,
  useActivateTerminal: () => noopMutation,
  useDeactivateTerminal: () => noopMutation,
  useToggleTrainingMode: () => noopMutation,
}))

// Keep the REAL TerminalForm — the location dropdown it renders is what these
// cases assert on. The list is stubbed down to just the edit affordance, which
// is how the edit-mode case gets `editingTerminal` set on the page.
vi.mock('@/features/pos/components', async () => {
  const actual =
    await vi.importActual<typeof import('@/features/pos/components')>('@/features/pos/components')
  return {
    ...actual,
    TerminalList: ({
      terminals,
      onEdit,
    }: {
      terminals: { id: string }[]
      onEdit: (terminal: unknown) => void
    }) => (
      <div>
        {terminals.map((terminal) => (
          <button
            key={terminal.id}
            type="button"
            onClick={() => {
              onEdit(terminal)
            }}
          >
            {`edit-${terminal.id}`}
          </button>
        ))}
      </div>
    ),
  }
})

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

function location(overrides: { id: string; name: string; code: string; posEnabled: boolean }) {
  return {
    companyId: 'company-1',
    type: 'shop' as const,
    phone: null,
    email: null,
    addressStreet: null,
    addressCity: null,
    addressPostalCode: null,
    addressCountry: null,
    taxId: null,
    vatNumber: null,
    legalIdentifiers: null,
    isDefault: false,
    isActive: true,
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <TerminalsPage />
    </QueryClientProvider>
  )
}

async function openCreateForm() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: /pos:terminal.addTerminal/ }))
  return user
}

beforeEach(() => {
  vi.clearAllMocks()
  terminalState.current = []
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Admin',
      email: 'a@a',
      tenant_id: 'tenant-1',
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('TerminalsPage — B-3 pos_enabled', () => {
  it('does not offer a POS-disabled location in the create form', async () => {
    mockGetLocations.mockResolvedValue([
      location({ id: 'loc-shop', name: 'Front Shop', code: 'SHOP', posEnabled: true }),
      location({ id: 'loc-wh', name: 'Back Warehouse', code: 'WH', posEnabled: false }),
    ])

    renderPage()
    await waitFor(() => {
      expect(mockGetLocations).toHaveBeenCalled()
    })
    await openCreateForm()

    expect(await screen.findByRole('option', { name: 'Front Shop (SHOP)' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Back Warehouse (WH)' })).not.toBeInTheDocument()
  })

  it('explains why the dropdown is empty when no location has POS enabled', async () => {
    mockGetLocations.mockResolvedValue([
      location({ id: 'loc-wh', name: 'Back Warehouse', code: 'WH', posEnabled: false }),
    ])

    renderPage()
    await waitFor(() => {
      expect(mockGetLocations).toHaveBeenCalled()
    })
    await openCreateForm()

    expect(await screen.findByText('pos:terminal.noPosEnabledLocations')).toBeInTheDocument()
  })

  /**
   * Gate r2 / M-2 — the subtlest branch of the filter.
   *
   * Editing is NOT an acquisition and is not gated by B-3, so the terminal's
   * OWN location must stay in the dropdown even when POS is switched off there
   * — otherwise the control is blanked, the admin cannot see where the terminal
   * actually is, and the next save silently relocates it. The exemption is
   * narrow on purpose: it admits that one location by id, not every disabled
   * one, so an edit still cannot MOVE a terminal onto a POS-disabled location.
   */
  it('keeps only the edited terminal own POS-disabled location in the dropdown', async () => {
    mockGetLocations.mockResolvedValue([
      location({ id: 'loc-shop', name: 'Front Shop', code: 'SHOP', posEnabled: true }),
      location({ id: 'loc-wh', name: 'Back Warehouse', code: 'WH', posEnabled: false }),
      location({ id: 'loc-other', name: 'Other Warehouse', code: 'WH2', posEnabled: false }),
    ])
    // The terminal under edit lives at the POS-disabled `loc-wh`.
    terminalState.current = [
      {
        id: 'term-1',
        type: 'physical',
        code: 'POS01',
        name: 'Warehouse Till',
        description: null,
        location_id: 'loc-wh',
        location: { id: 'loc-wh', name: 'Back Warehouse', code: 'WH' },
        is_active: true,
        is_training_mode: false,
        has_history: false,
        activated_at: '2026-01-01T00:00:00Z',
        deactivated_at: null,
        deactivation_reason: null,
        current_sequence: 0,
        current_year: 2026,
        fiscal_schema_version: 3,
        max_discount_percent: '100.00',
        allow_line_discounts: true,
        allow_transaction_discounts: true,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
      },
    ]

    const user = userEvent.setup()
    renderPage()
    await waitFor(() => {
      expect(mockGetLocations).toHaveBeenCalled()
    })
    await user.click(await screen.findByRole('button', { name: 'edit-term-1' }))

    // Its own disabled location survives, so the value is visible and keepable.
    expect(
      await screen.findByRole('option', { name: 'Back Warehouse (WH)' })
    ).toBeInTheDocument()
    // A POS-enabled location is still offered — an edit may move it there.
    expect(screen.getByRole('option', { name: 'Front Shop (SHOP)' })).toBeInTheDocument()
    // Any OTHER disabled location stays excluded: the exemption is by id, not
    // a blanket "show everything while editing".
    expect(screen.queryByRole('option', { name: 'Other Warehouse (WH2)' })).not.toBeInTheDocument()
    // Create-mode-only empty state must not appear while editing.
    expect(screen.queryByText('pos:terminal.noPosEnabledLocations')).not.toBeInTheDocument()
  })

  it('surfaces the LOCATION_POS_DISABLED cause instead of a generic create error', async () => {
    mockGetLocations.mockResolvedValue([
      location({ id: 'loc-shop', name: 'Front Shop', code: 'SHOP', posEnabled: true }),
    ])
    // Shaped exactly like the server's refusal, through the real `isApiError`
    // guard: an axios error whose response body carries the `error` envelope.
    mockCreateMutateAsync.mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { error: { code: 'LOCATION_POS_DISABLED', message: 'POS is not enabled…' } },
      },
    })

    renderPage()
    await waitFor(() => {
      expect(mockGetLocations).toHaveBeenCalled()
    })
    const user = await openCreateForm()

    await user.type(screen.getByLabelText(/pos:terminal.name/), 'Till 1')
    await user.selectOptions(screen.getByLabelText(/pos:terminal.location/), 'loc-shop')
    await user.click(screen.getByRole('button', { name: /common:common.create/ }))

    await waitFor(() => {
      expect(mockToastError).toHaveBeenCalledWith('pos:terminal.locationPosDisabled')
    })
    expect(mockToastError).not.toHaveBeenCalledWith(
      expect.stringContaining('common:common.errorCreating')
    )
  })
})
