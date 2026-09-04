import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { useViewScopeStore } from '../../stores/viewScopeStore'
import { resetAuth, seedAuth } from '../../test/seedAuth'
import {
  StockMovementsPage,
  type StockMovement,
  type StockMovementsResponse,
} from './StockMovementsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ to, children, ...props }: { to: string; children: ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

// Bound to the REAL `viewScopeStore` (only `useScopedLocations`' network path and
// the clamping effect are cut away), so `useViewScopeStore.setState` in a test
// re-renders the page exactly as the location selector would.
vi.mock('../locations/hooks/useViewScope', async () => {
  const { useViewScopeStore } = await vi.importActual<
    typeof import('../../stores/viewScopeStore')
  >('../../stores/viewScopeStore')
  return {
    useViewScope: () => {
      const scope = useViewScopeStore((state) => state.scope)
      return {
        scope,
        effectiveLocationIds: scope === 'all' ? [] : scope,
        isAll: scope === 'all',
        setScope: useViewScopeStore.getState().setScope,
      }
    },
  }
})
vi.mock('../../hooks/useLocation', () => ({ useLocation: () => ({ currentLocationId: null }) }))
vi.mock('../locations/LocationSelector', () => ({
  LocationSelector: () => <div data-testid="location-selector" />,
}))
// Granted, not denied: the guard's stated purpose includes keeping the
// reverse-write-off action off foreign rows, so the action column must actually
// render for these tests to mean what the page comment claims.
vi.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))
vi.mock('../batches/api/batches', () => ({ reverseWriteOff: vi.fn() }))

const apiGet = vi.hoisted(() => vi.fn<(url: string) => Promise<MovementsBody>>())
vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: { ...actual.api, get: (url: string): Promise<MovementsBody> => apiGet(url) },
  }
})

function makeMovement(overrides: Partial<StockMovement>): StockMovement {
  return {
    id: 'movement-0',
    product_id: 'product-0',
    product_name: 'Widget',
    location_id: 'location-1',
    location_name: 'Main',
    movement_type: 'receipt',
    reason: null,
    quantity: '5.0000',
    quantity_decimals: 3,
    quantity_before: '0.0000',
    quantity_after: '5.0000',
    reference: 'REF-0',
    reference_type: null,
    source_document_id: null,
    source_document_type: null,
    notes: null,
    user_id: 'user-1',
    user_name: 'Alice',
    reverses_movement_id: null,
    is_reversed: false,
    created_at: '2026-09-01T10:00:00Z',
    ...overrides,
  }
}

interface MovementsBody {
  data: StockMovementsResponse
}

function body(rows: StockMovement[], page: number, lastPage: number): MovementsBody {
  return {
    data: {
      data: rows,
      meta: {
        current_page: page,
        last_page: lastPage,
        per_page: 25,
        total: rows.length,
        from: rows.length === 0 ? null : 1,
        to: rows.length === 0 ? null : rows.length,
      },
    },
  }
}

/** A request that stays in flight for the whole assertion window. */
function neverSettles(): Promise<MovementsBody> {
  return new Promise<MovementsBody>(() => { /* intentionally pending */ })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <StockMovementsPage />
    </QueryClientProvider>,
  )
}

const companyOneMovement = makeMovement({ id: 'm-c1', product_name: 'COMPANY-ONE-WIDGET' })

describe('StockMovementsPage scope-change placeholder', () => {
  beforeEach(() => {
    apiGet.mockReset()
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
    useViewScopeStore.setState({ scope: ['location-1'] })
  })

  afterEach(() => {
    // Unmount BEFORE clearing the stores: vitest runs this hook ahead of RTL's
    // auto-cleanup, so a bare `resetAuth()` would push a store update into a
    // still-mounted tree outside `act`.
    cleanup()
    resetAuth()
    useViewScopeStore.setState({ scope: 'all' })
  })

  it('renders no company-one movement while company two is still loading', async () => {
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOneMovement], 1, 1)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('COMPANY-ONE-WIDGET')

    act(() => {
      useCompanyStore.setState({ currentCompanyId: 'company-2' })
    })

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    expect(screen.queryByText('COMPANY-ONE-WIDGET')).not.toBeInTheDocument()
  })

  it('renders no previous-tenant movement while the new tenant is still loading', async () => {
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOneMovement], 1, 1)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('COMPANY-ONE-WIDGET')

    act(() => {
      const user = useAuthStore.getState().user
      if (user === null) throw new Error('expected a seeded user')
      useAuthStore.setState({ user: { ...user, tenant_id: 'tenant-2' } })
    })

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    expect(screen.queryByText('COMPANY-ONE-WIDGET')).not.toBeInTheDocument()
  })

  it('renders no location-one movement while location two is still loading', async () => {
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOneMovement], 1, 1)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('COMPANY-ONE-WIDGET')

    act(() => {
      useViewScopeStore.setState({ scope: ['location-2'] })
    })

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    // `locationScopedKey` carries the location scope, so the placeholder crosses
    // that boundary too — a stock reconciliation screen must not attribute one
    // location's movements to another.
    expect(screen.queryByText('COMPANY-ONE-WIDGET')).not.toBeInTheDocument()
  })

  it('restarts traversal at page one when the company changes, without requesting the stale offset', async () => {
    const user = userEvent.setup()
    apiGet.mockImplementation(() => Promise.resolve(body([companyOneMovement], 1, 3)))

    renderPage()
    await screen.findByText('COMPANY-ONE-WIDGET')
    await user.click(screen.getByRole('button', { name: 'pagination.next' }))
    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(expect.stringMatching(/[?&]page=2&/))
    })

    apiGet.mockClear()
    act(() => {
      useCompanyStore.setState({ currentCompanyId: 'company-2' })
    })

    // Page 2 of company one's result set is meaningless for company two: left
    // uncorrected the operator lands on an empty table and reads it as "this
    // company has no movements".
    await waitFor(() => { expect(apiGet).toHaveBeenCalled() })
    // Anchored on a `?`/`&` delimiter, not a bare `page=`: `per_page=25`
    // contains the substring `page=2`, which would satisfy a plain
    // `stringContaining` for the wrong reason.
    expect(apiGet).not.toHaveBeenCalledWith(expect.stringMatching(/[?&]page=2&/))
    expect(apiGet).toHaveBeenCalledWith(expect.stringMatching(/[?&]page=1&/))
  })

  it('keeps the previous page visible while the next page of the SAME company loads', async () => {
    const user = userEvent.setup()
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOneMovement], 1, 2)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('COMPANY-ONE-WIDGET')

    await user.click(screen.getByRole('button', { name: 'pagination.next' }))

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    // The legitimate reason `placeholderData: keepPreviousData` is here at all:
    // paging inside one company must not flash an empty table.
    expect(screen.getByText('COMPANY-ONE-WIDGET')).toBeInTheDocument()
  })
})
