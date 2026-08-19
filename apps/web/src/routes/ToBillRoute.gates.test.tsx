import { act, screen } from '@testing-library/react'
import { Outlet } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { defaultCompanyConfig, mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { useAuthStore } from '@/stores/authStore'
import { AppRoutes } from './index'

vi.mock('../components/layout/Layout', () => ({ Layout: () => <Outlet /> }))
vi.mock('../features/documents/to-bill/ToBillPage', () => ({ ToBillPage: () => <main>to-bill protected page</main> }))
vi.mock('../features/dashboard/Dashboard', () => ({ Dashboard: () => <main>dashboard fallback</main> }))

function setPermissions(permissions: string[]) {
  const user = useAuthStore.getState().user
  if (user === null) throw new Error('Expected an authenticated test user')
  act(() => { useAuthStore.getState().setUser({ ...user, permissions }) })
}

describe('to-bill route gates', () => {
  beforeEach(() => {
    act(() => { seedAuth() })
  })

  afterEach(() => {
    act(() => { resetAuth() })
  })

  it('does not render for an admin when Sales is disabled, independently of permissions', async () => {
    act(() => { seedAuth({ roles: ['admin'] }) })
    setPermissions(['deliveries.view'])
    renderWithProviders(<AppRoutes />, { route: '/sales/to-bill', companyConfig: defaultCompanyConfig })

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('to-bill protected page')).not.toBeInTheDocument()
  })

  it('does not render without deliveries.view when Sales is enabled', async () => {
    setPermissions([])
    renderWithProviders(<AppRoutes />, { route: '/sales/to-bill', companyConfig: mechanicCompanyConfig })

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('to-bill protected page')).not.toBeInTheDocument()
  })

  it('renders only when Sales and deliveries.view are both present', async () => {
    setPermissions(['deliveries.view'])
    renderWithProviders(<AppRoutes />, { route: '/sales/to-bill', companyConfig: mechanicCompanyConfig })

    expect(await screen.findByText('to-bill protected page')).toBeInTheDocument()
  })
})
