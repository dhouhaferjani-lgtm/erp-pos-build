import { act, screen } from '@testing-library/react'
import { Outlet } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { useAuthStore } from '@/stores/authStore'
import { AppRoutes } from './index'

vi.mock('../components/layout/Layout', () => ({ Layout: () => <Outlet /> }))
vi.mock('../features/documents/delivery-notes', () => ({
  DeliveryNoteDetailPage: () => <main>delivery-note detail page</main>,
}))
vi.mock('../features/dashboard/Dashboard', () => ({ Dashboard: () => <main>dashboard fallback</main> }))

function setPermissions(permissions: string[]) {
  const user = useAuthStore.getState().user
  if (user === null) throw new Error('Expected an authenticated test user')
  act(() => {
    useAuthStore.getState().setUser({ ...user, permissions })
  })
}

/**
 * Retirement regression for `/inventory/delivery-notes/consolidate`.
 *
 * The consolidation route, page and component were deleted in Phase 2.4.2. No
 * dedicated route declaration remains, so the literal segment `consolidate`
 * is matched by the generic `delivery-notes/:id` detail route with
 * `id === 'consolidate'` — that is what actually renders today, and it is
 * asserted here rather than assumed.
 *
 * The load-bearing part is the negative: if the consolidation route block is
 * ever re-introduced it would be declared before `delivery-notes/:id` (a
 * literal segment outranks a dynamic one in React Router's ranking), the
 * detail stub would stop rendering, and this test goes red.
 */
describe('retired delivery-note consolidation route', () => {
  beforeEach(() => {
    act(() => {
      seedAuth({ roles: ['admin'] })
    })
    setPermissions(['invoices.create', 'deliveries.view'])
  })

  afterEach(() => {
    act(() => {
      resetAuth()
    })
  })

  it('renders the generic delivery-note detail surface, not a consolidation surface', async () => {
    renderWithProviders(<AppRoutes />, {
      route: '/inventory/delivery-notes/consolidate',
      companyConfig: mechanicCompanyConfig,
    })

    expect(await screen.findByText('delivery-note detail page')).toBeInTheDocument()
    expect(screen.queryByText(/consolidat/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/regroup/i)).not.toBeInTheDocument()
    expect(screen.queryByText('dashboard fallback')).not.toBeInTheDocument()
  })
})
