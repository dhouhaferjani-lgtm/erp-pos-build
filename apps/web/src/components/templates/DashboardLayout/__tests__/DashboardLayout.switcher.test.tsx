import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { DashboardLayout } from '../DashboardLayout'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k, i18n: { changeLanguage: vi.fn(), language: 'en' } }),
}))

// Mock LocationSwitcher with a testid so we can assert presence/absence
vi.mock('../../../organisms/LocationSwitcher', () => ({
  LocationSwitcher: () => <div data-testid="location-switcher" />,
}))

// Mock TopBar as a thin shim that renders LocationSwitcher based on the prop —
// this verifies DashboardLayout computes and passes showLocationSwitcher correctly
// without pulling in TopBar's heavy auth/router deps.
vi.mock('../../../organisms/TopBar', () => ({
  TopBar: ({ showLocationSwitcher }: { showLocationSwitcher?: boolean }) => (
    <div data-testid="topbar">
      {showLocationSwitcher !== false && <div data-testid="location-switcher" />}
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

describe('DashboardLayout location switcher visibility', () => {
  it('hides the switcher on /reports', () => {
    renderAt('/reports')
    expect(screen.queryByTestId('location-switcher')).not.toBeInTheDocument()
  })

  it('shows the switcher on an operational route', () => {
    renderAt('/inventory')
    expect(screen.getByTestId('location-switcher')).toBeInTheDocument()
  })
})
