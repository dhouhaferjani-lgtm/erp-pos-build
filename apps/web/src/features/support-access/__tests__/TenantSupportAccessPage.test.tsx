import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { supportAccessFixture } from '../__fixtures__/supportAccess'
import { TenantSupportAccessPage } from '../pages/TenantSupportAccessPage'

const createWindow = vi.fn()
const approveGrant = vi.fn()
const rejectGrant = vi.fn()
const revokeGrant = vi.fn()

vi.mock('../hooks/useTenantSupportAccess', () => ({
  useTenantSupportAccess: () => ({
    overview: supportAccessFixture,
    isLoading: false,
    error: null,
    createWindow,
    approveGrant,
    rejectGrant,
    revokeGrant,
    isMutating: false,
  }),
}))

describe('TenantSupportAccessPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows tenant controls and sanitized history to a viewer', () => {
    setUser(['support-access.view'])
    renderPage()

    expect(screen.getByRole('heading', { name: /support access/i })).toBeInTheDocument()
    expect(screen.getAllByText('SUP-9000').length).toBeGreaterThan(0)
    expect(screen.getByText(/products.index/i)).toBeInTheDocument()
    expect(screen.queryByText(/operator@example/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument()
  })

  it('allows managers to pre-grant, approve, reject, and revoke', async () => {
    setUser(['support-access.view', 'support-access.manage'])
    const user = userEvent.setup()
    renderPage()

    expect(screen.getByRole('button', { name: /approve/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /reject/i })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /revoke/i }).length).toBeGreaterThan(0)

    await user.type(screen.getByLabelText(/support reason/i), 'Quarterly support window')
    await user.type(screen.getByLabelText(/ticket reference/i), 'SUP-9010')
    await user.click(screen.getByRole('button', { name: /create support window/i }))
    expect(createWindow).toHaveBeenCalled()
  })
})

function setUser(permissions: string[]) {
  useAuthStore.getState().setAuth({
    id: 'tenant-admin',
    name: 'Tenant Admin',
    email: 'admin@tenant.test',
    tenant_id: 'tenant-1',
    roles: ['admin'],
    permissions,
    email_verified_at: null,
    impersonation: null,
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <TenantSupportAccessPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
