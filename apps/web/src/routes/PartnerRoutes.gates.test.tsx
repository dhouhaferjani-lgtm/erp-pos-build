import { act, screen } from '@testing-library/react'
import { Outlet } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { useAuthStore } from '@/stores/authStore'
import { AppRoutes } from './index'

vi.mock('../components/layout/Layout', () => ({ Layout: () => <Outlet /> }))
vi.mock('../features/dashboard/Dashboard', () => ({ Dashboard: () => <main>dashboard fallback</main> }))
vi.mock('../features/partners/PartnerForm', () => ({ PartnerForm: () => <main>partner edit form</main> }))
vi.mock('../features/partners/PartnerDetailPage', () => ({ PartnerDetailPage: () => <main>partner detail</main> }))
vi.mock('../features/partners/PartnerListPage', () => ({ PartnerListPage: () => <main>partner list</main> }))
vi.mock('../features/crm/pages/ContactFormPage', () => ({ ContactFormPage: () => <main>contact edit form</main> }))

function setActor(roles: string[], permissions: string[]): void {
  const user = useAuthStore.getState().user
  if (user === null) throw new Error('Expected an authenticated test user')

  act(() => {
    useAuthStore.getState().setUser({ ...user, roles, permissions })
  })
}

function renderRoute(path: string): void {
  renderWithProviders(<AppRoutes />, { route: path })
}

describe('partner and contact route gates', () => {
  beforeEach(() => {
    act(() => { seedAuth() })
  })

  afterEach(() => {
    act(() => { resetAuth() })
  })

  it.each([
    ['owner role', ['admin'], []],
    ['partners.update permission', [], ['partners.update']],
  ])('%s opens both partner edit routes', async (_label, roles, permissions) => {
    setActor(roles, permissions)

    const customer = renderWithProviders(<AppRoutes />, { route: '/sales/customers/customer-1/edit' })
    expect(await screen.findByText('partner edit form')).toBeInTheDocument()
    customer.unmount()

    renderRoute('/purchases/suppliers/supplier-1/edit')
    expect(await screen.findByText('partner edit form')).toBeInTheDocument()
  })

  it.each([
    '/sales/customers/customer-1/edit',
    '/purchases/suppliers/supplier-1/edit',
  ])('blocks a contacts.update-only user from %s', async (path) => {
    setActor([], ['contacts.update'])

    renderRoute(path)

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('partner edit form')).not.toBeInTheDocument()
  })

  it.each([
    ['partners.view only', ['partners.view']],
    ['purchases.view only', ['purchases.view']],
  ])('blocks the supplier list with %s', async (_label, permissions) => {
    setActor([], permissions)
    renderRoute('/purchases/suppliers')

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('partner list')).not.toBeInTheDocument()
  })

  it('keeps the supplier list and detail coherent when both gates pass', async () => {
    setActor([], ['partners.view', 'purchases.view'])

    const list = renderWithProviders(<AppRoutes />, { route: '/purchases/suppliers' })
    expect(await screen.findByText('partner list')).toBeInTheDocument()
    list.unmount()

    renderRoute('/purchases/suppliers/supplier-1')
    expect(await screen.findByText('partner detail')).toBeInTheDocument()
  })

  it('keeps the true contact edit route gated by contacts.update', async () => {
    setActor([], ['contacts.update'])
    const allowed = renderWithProviders(<AppRoutes />, { route: '/crm/contacts/contact-1/edit' })
    expect(await screen.findByText('contact edit form')).toBeInTheDocument()
    allowed.unmount()

    setActor([], ['partners.update'])
    renderRoute('/crm/contacts/contact-1/edit')
    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
    expect(screen.queryByText('contact edit form')).not.toBeInTheDocument()
  })

  it('does not resolve the retired Companies route', async () => {
    setActor(['admin'], [])

    renderRoute('/crm/companies')

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
  })

  it.each(['/partners', '/partners/legacy-id'])('keeps %s redirecting to customers', async (path) => {
    setActor(['admin'], [])

    renderRoute(path)

    expect(await screen.findByText('partner list')).toBeInTheDocument()
  })
})
