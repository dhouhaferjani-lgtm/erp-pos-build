import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { DashboardLayout } from '../DashboardLayout'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k, i18n: { changeLanguage: vi.fn(), language: 'en' } }),
}))

// Mock TopBar as a thin shim; the canonical view-scope picker is always present.
vi.mock('../../../organisms/TopBar', () => ({
  TopBar: () => (
    <div data-testid="topbar">
      <div data-testid="view-scope-picker" />
    </div>
  ),
}))

vi.mock('../../../organisms/Sidebar', () => ({
  Sidebar: () => <div data-testid="sidebar" />,
}))

vi.mock('../../../organisms/EmailVerificationBanner', () => ({
  EmailVerificationBanner: () => null,
}))

vi.mock('../../../organisms/CommandPalette', () => ({
  CommandPalette: () => null,
}))

vi.mock('../../../molecules/Breadcrumb', () => ({
  Breadcrumb: () => null,
}))

vi.mock('../../../../providers/WebSocketReconnectProvider', () => ({
  WebSocketReconnectProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

vi.mock('../../../../contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({ product: 'otospex', isIziPOS: false, isOtospex: true }),
}))

vi.mock('../../../../features/import/hooks/useImportProgress', () => ({
  useImportProgress: () => undefined,
}))

vi.mock('../../../organisms/GlobalImportProgress/GlobalImportProgress', () => ({
  GlobalImportProgress: () => null,
}))

import React from 'react'

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<DashboardLayout />}>
          <Route path={path} element={<div>page</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

describe('DashboardLayout view scope visibility', () => {
  it('shows the view scope picker on /reports', () => {
    renderAt('/reports')
    expect(screen.getByTestId('view-scope-picker')).toBeInTheDocument()
  })

  it('shows the view scope picker on an operational route', () => {
    renderAt('/inventory')
    expect(screen.getByTestId('view-scope-picker')).toBeInTheDocument()
  })
})
