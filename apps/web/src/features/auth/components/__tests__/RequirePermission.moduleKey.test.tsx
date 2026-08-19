/**
 * T4 (UI-01): `RequirePermission moduleKey=...` must deny an unrecognised key.
 *
 * `canAccessModule` used to return `true` for any key outside
 * `MODULE_PERMISSIONS`, so a route guarded by a dead key (e.g.
 * `moduleKey="parts_catalog"`) rendered its children for everyone. The guard
 * now fails closed: unknown key -> no children.
 */
import { describe, expect, it, vi, afterEach } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import type { ModuleKey } from '@/hooks/usePermissions'
import { RequirePermission } from '../RequirePermission'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: string) => fallback ?? key }),
}))

afterEach(() => {
  resetAuth()
})

describe('RequirePermission moduleKey gating', () => {
  it('does not render children for an unrecognised module key', () => {
    seedAuth({ roles: ['admin'] })

    renderWithProviders(
      // Cast is deliberate: the ModuleKey union rejects this literal at
      // compile time, which is the point of T4 — the test forces the runtime
      // branch a stale call site would hit.
      <RequirePermission moduleKey={'parts_catalog' as ModuleKey}>
        <div>guarded-content</div>
      </RequirePermission>,
      { route: '/parts-catalog' },
    )

    expect(screen.queryByText('guarded-content')).not.toBeInTheDocument()
  })

  it('renders children for a recognised module key the role holds', () => {
    seedAuth({ roles: ['admin'] })

    renderWithProviders(
      <RequirePermission moduleKey="settings">
        <div>guarded-content</div>
      </RequirePermission>,
      { route: '/settings' },
    )

    expect(screen.getByText('guarded-content')).toBeInTheDocument()
  })

  it('does not render children for a recognised module key the role lacks', () => {
    seedAuth({ roles: ['cashier'] })

    renderWithProviders(
      <RequirePermission moduleKey="treasury">
        <div>guarded-content</div>
      </RequirePermission>,
      { route: '/treasury/payments' },
    )

    expect(screen.queryByText('guarded-content')).not.toBeInTheDocument()
  })
})
