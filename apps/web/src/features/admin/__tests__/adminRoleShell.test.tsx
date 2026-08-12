import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AdminIndexRedirect, RequireAdminRole } from '../components/AdminRoleGuard'
import { adminRoutePolicies, canAccessAdminRoute, homeForAdminRole } from '../lib/adminRolePolicy'
import { AdminLayout } from '../components/AdminLayout'
import { AdminLoginPage } from '../pages/AdminLoginPage'
import { useAdminAuthStore, type AdminRole } from '../stores/adminAuthStore'
import { loginSuperAdmin } from '../api'

vi.mock('../api', () => ({ loginSuperAdmin: vi.fn(), logoutSuperAdmin: vi.fn() }))

const administrators: Record<AdminRole, { id: string; email: string; name: string; role: AdminRole }> = {
  super_admin: { id: 'super', email: 'super@example.test', name: 'Super', role: 'super_admin' },
  defaults_editor: { id: 'defaults', email: 'defaults@example.test', name: 'Defaults', role: 'defaults_editor' },
  support_approver: { id: 'support', email: 'support@example.test', name: 'Support', role: 'support_approver' },
}

function authenticate(role: AdminRole) {
  useAdminAuthStore.getState().setAuth(administrators[role], `${role}-token`)
}

describe('three-role admin shell', () => {
  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    vi.mocked(loginSuperAdmin).mockReset()
  })

  it.each([
    ['super_admin', '/admin/dashboard'],
    ['defaults_editor', '/admin/country-defaults'],
    ['support_approver', '/admin/support-access'],
  ] as const)('lands %s on its permitted home', (role, expectedHome) => {
    expect(homeForAdminRole(role)).toBe(expectedHome)
    authenticate(role)
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminIndexRedirect />} />
          <Route path="/admin/dashboard" element={<div>Dashboard home</div>} />
          <Route path="/admin/country-defaults" element={<div>Defaults home</div>} />
          <Route path="/admin/support-access" element={<div>Support home</div>} />
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText(/home$/)).toBeInTheDocument()
  })

  it.each([
    ['super_admin', '/admin/dashboard'],
    ['defaults_editor', '/admin/country-defaults'],
    ['support_approver', '/admin/support-access'],
  ] as const)('sends %s login success to %s', async (role, expectedHome) => {
    vi.mocked(loginSuperAdmin).mockResolvedValue({ admin: administrators[role], token: `${role}-token` })
    render(
      <MemoryRouter initialEntries={['/admin/login']}>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
          <Routes>
            <Route path="/admin/login" element={<AdminLoginPage />} />
            <Route path={expectedHome} element={<div>Role login home</div>} />
          </Routes>
        </QueryClientProvider>
      </MemoryRouter>
    )
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: administrators[role].email } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => { expect(screen.getByText('Role login home')).toBeInTheDocument() })
  })

  it('declares the complete protected admin route inventory and exact role matrix', () => {
    expect(Object.values(adminRoutePolicies).map((policy) => policy.path)).toEqual([
      'dashboard', 'support-access', 'tenants', 'company-owners', 'verticals', 'audit-logs',
      'billing', 'billing/subscriptions', 'billing/invoices', 'billing/payments', 'monitoring',
      'country-defaults', 'country-defaults/templates/:templateId', 'country-defaults/assignments',
    ])
    expect(Object.values(adminRoutePolicies).filter((policy) => canAccessAdminRoute(policy, 'super_admin')).length).toBe(14)
    expect(Object.values(adminRoutePolicies).filter((policy) => canAccessAdminRoute(policy, 'defaults_editor')).map((policy) => policy.path)).toEqual([
      'country-defaults', 'country-defaults/templates/:templateId', 'country-defaults/assignments',
    ])
    expect(Object.values(adminRoutePolicies).filter((policy) => canAccessAdminRoute(policy, 'support_approver')).map((policy) => policy.path)).toEqual([
      'support-access',
    ])
  })

  it.each(['super_admin', 'defaults_editor', 'support_approver'] as const)('enforces every production route policy for %s', (role) => {
    authenticate(role)
    for (const [routeName, policy] of Object.entries(adminRoutePolicies)) {
      const page = `${routeName} route`
      const view = render(
        <MemoryRouter initialEntries={[policy.testHref]}>
          <Routes>
            <Route path={policy.href} element={<RequireAdminRole allow={policy.roles}><div>{page}</div></RequireAdminRole>} />
            <Route path={homeForAdminRole(role)} element={<div>Permitted home</div>} />
          </Routes>
        </MemoryRouter>
      )
      if (canAccessAdminRoute(policy, role)) expect(screen.getByText(page)).toBeInTheDocument()
      else expect(screen.getByText('Permitted home')).toBeInTheDocument()
      view.unmount()
    }
  })

  it.each([
    ['super_admin', ['Dashboard', 'Tenants', 'Verticals', 'Company owners', 'Billing', 'Monitoring', 'Audit logs', 'Country defaults', 'Assignments', 'Support access']],
    ['defaults_editor', ['Country defaults', 'Assignments']],
    ['support_approver', ['Support access']],
  ] as const)('filters navigation exactly for %s', (role, visibleLabels) => {
    authenticate(role)
    render(
      <MemoryRouter>
        <QueryClientProvider client={new QueryClient()}>
          <AdminLayout />
        </QueryClientProvider>
      </MemoryRouter>
    )
    const navigation = screen.getByRole('navigation')
    expect(within(navigation).getAllByRole('link').map((link) => link.textContent.trim())).toEqual(visibleLabels)
  })
})
