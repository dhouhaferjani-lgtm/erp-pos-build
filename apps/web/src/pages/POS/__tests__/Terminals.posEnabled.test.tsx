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

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({ data: [], isLoading: false }),
  useCreateTerminal: () => ({ mutateAsync: mockCreateMutateAsync, isPending: false }),
  useUpdateTerminal: () => noopMutation,
  useArchiveTerminal: () => noopMutation,
  useDeleteTerminal: () => noopMutation,
  useActivateTerminal: () => noopMutation,
  useDeactivateTerminal: () => noopMutation,
  useToggleTrainingMode: () => noopMutation,
}))

// Keep the REAL TerminalForm — the location dropdown it renders is what these
// cases assert on. Only the list is stubbed out.
vi.mock('@/features/pos/components', async () => {
  const actual =
    await vi.importActual<typeof import('@/features/pos/components')>('@/features/pos/components')
  return { ...actual, TerminalList: () => null }
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
