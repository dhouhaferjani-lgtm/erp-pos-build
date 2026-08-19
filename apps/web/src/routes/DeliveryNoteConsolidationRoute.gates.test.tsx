import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, screen } from '@testing-library/react'
import { Outlet } from 'react-router-dom'
import { renderWithProviders } from '@/test/renderWithProviders'
import { mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { useAuthStore } from '@/stores/authStore'
import { AppRoutes } from './index'

vi.mock('../components/layout/Layout', () => ({
  Layout: () => <Outlet />,
}))

vi.mock('../features/documents/DeliveryNoteConsolidationPage', () => ({
  DeliveryNoteConsolidationPage: () => <main>delivery-note consolidation protected page</main>,
}))

vi.mock('../features/dashboard/Dashboard', () => ({
  Dashboard: () => <main>dashboard fallback</main>,
}))

function setInvoiceCreationPermission() {
  const user = useAuthStore.getState().user
  if (user === null) {
    throw new Error('Expected an authenticated test user')
  }

  act(() => {
    useAuthStore.getState().setUser({ ...user, permissions: ['invoices.create'] })
  })
}

describe('retired delivery-note consolidation route', () => {
  beforeEach(() => {
    act(() => {
      seedAuth({ roles: ['admin'] })
    })
    setInvoiceCreationPermission()
  })

  afterEach(() => {
    act(() => {
      resetAuth()
    })
  })

  it('falls through to the dashboard even when Sales and invoice creation are enabled', async () => {
    renderWithProviders(<AppRoutes />, {
      route: '/inventory/delivery-notes/consolidate',
      companyConfig: mechanicCompanyConfig,
    })

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('delivery-note consolidation protected page')).not.toBeInTheDocument()
  })
})
