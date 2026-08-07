import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'
import { useAuthStore } from '@/stores/authStore'

vi.mock('@/features/auth/AuthProvider', () => ({ AuthProvider: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/features/company/CompanyProvider', () => ({ CompanyProvider: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/features/locations/LocationProvider', () => ({ LocationProvider: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/contexts/CompanyConfigContext', () => ({ CompanyConfigProvider: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/contexts/ProductConfigContext', () => ({ ProductConfigProvider: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/components/ErrorBoundary', () => ({ ErrorBoundary: ({ children }: { children: ReactNode }) => children }))
vi.mock('@/components/CookieConsent', () => ({ CookieConsent: () => null }))
vi.mock('@/routes', () => ({ AppRoutes: () => <main>Current route</main> }))
vi.mock('sonner', () => ({ Toaster: () => null }))

describe('App impersonation perimeter', () => {
  beforeEach(() => {
    useAuthStore.getState().setAuth({
      id: 'subject-1',
      name: 'Tenant Subject',
      email: 'subject@test',
      tenant_id: 'tenant-1',
      roles: ['admin'],
      permissions: ['products.view'],
      email_verified_at: null,
      impersonation: {
        session_id: 'session-1',
        subject_user_id: 'subject-1',
        subject_name: 'Tenant Subject',
        reason: 'Investigate stock mismatch',
        ticket_ref: 'SUP-9000',
        access_level: 'read_only',
        expires_at: new Date(Date.now() + 300_000).toISOString(),
        remaining_seconds: 300,
      },
    }, 'impersonation-token')
  })

  it('mounts the persistent banner above every route at the application root', () => {
    render(
      <MemoryRouter initialEntries={['/pos']}>
        <App />
      </MemoryRouter>,
    )

    expect(screen.getByRole('region', { name: /support session as/i })).toHaveTextContent('Tenant Subject')
    expect(screen.getByText('Current route')).toBeInTheDocument()
  })
})
