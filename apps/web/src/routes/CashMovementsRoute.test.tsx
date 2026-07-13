import { render, screen } from '@testing-library/react'
import { MemoryRouter, Outlet } from 'react-router-dom'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { AppRoutes } from './index'

const mockRequirePermission = vi.hoisted(() => vi.fn())

vi.mock('../features/auth/AuthProvider', () => ({
  RequireAuth: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

vi.mock('../components/layout/Layout', () => ({
  Layout: () => <Outlet />,
}))

vi.mock('../components/auth', () => ({
  RequirePermission: ({
    children,
    permission,
  }: {
    children: ReactNode
    permission?: string
  }) => {
    mockRequirePermission(permission)
    return permission === 'reports.view' ? <>{children}</> : null
  },
}))

vi.mock('../features/finance/pages/CashMovementsReportPage', () => ({
  CashMovementsReportPage: () => <div>cash movements route page</div>,
}))

describe('cash movements report route', () => {
  it('lazy-renders the page behind reports.view', async () => {
    render(
      <MemoryRouter initialEntries={['/finance/cash-movements']}>
        <AppRoutes />
      </MemoryRouter>,
    )

    expect(await screen.findByText('cash movements route page')).toBeInTheDocument()
    expect(mockRequirePermission).toHaveBeenCalledWith('reports.view')
  })
})
