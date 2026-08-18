import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Outlet } from 'react-router-dom'
import type { ReactNode } from 'react'
import { useAuthStore } from '@/stores/authStore'
import { AppRoutes } from './index'

vi.mock('../features/auth/AuthProvider', () => ({
  RequireAuth: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

vi.mock('../components/layout/Layout', () => ({
  Layout: () => <Outlet />,
}))

vi.mock('../features/dashboard/Dashboard', () => ({
  Dashboard: () => <div>dashboard fallback</div>,
}))

vi.mock('../features/compliance/pages/FraudSettingsPage', () => ({
  FraudSettingsPage: () => <div>fraud settings route</div>,
}))

vi.mock('../features/compliance/pages/FraudAlertsPage', () => ({
  FraudAlertsPage: () => <div>fraud alerts route</div>,
}))

vi.mock('../features/compliance/pages/ComplianceExportPage', () => ({
  ComplianceExportPage: () => <div>compliance export route</div>,
}))

function setPermissions(permissions: string[]) {
  useAuthStore.getState().setUser({
    id: 'user-1',
    name: 'Route Gate User',
    email: 'route-gate@example.test',
    tenant_id: 'tenant-1',
    roles: [],
    permissions,
    email_verified_at: null,
  })
}

function renderRoute(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AppRoutes />
    </MemoryRouter>,
  )
}

describe('compliance route permissions', () => {
  beforeEach(() => {
    localStorage.clear()
    useAuthStore.getState().logout()
  })

  it.each([
    ['/settings/compliance/export', ['compliance.verify_chains'], 'compliance export route'],
    ['/settings/compliance/fraud-settings', ['fraud-settings.view'], 'fraud settings route'],
    ['/settings/compliance/fraud-alerts', ['fraud-alerts.view'], 'fraud alerts route'],
  ])('mounts %s through its exact permission', async (path, permissions, content) => {
    setPermissions(permissions)

    renderRoute(path)

    expect(await screen.findByText(content)).toBeInTheDocument()
  })

  it.each([
    '/settings/compliance/export',
    '/settings/compliance/fraud-settings',
    '/settings/compliance/fraud-alerts',
  ])('redirects a settings.view-only user away from %s', async (path) => {
    setPermissions(['settings.view'])

    renderRoute(path)

    expect(await screen.findByText('dashboard fallback')).toBeInTheDocument()
  })
})
