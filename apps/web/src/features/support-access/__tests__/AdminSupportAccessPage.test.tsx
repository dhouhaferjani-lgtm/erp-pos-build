import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAdminAuthStore } from '@/features/admin/stores/adminAuthStore'
import { useAuthStore } from '@/stores/authStore'
import { supportAccessFixture } from '../__fixtures__/supportAccess'
import { AdminSupportAccessPage } from '../pages/AdminSupportAccessPage'

const requestAccess = vi.fn()
const approveGrant = vi.fn()
const revokeGrant = vi.fn()
const startSession = vi.fn()
const requestElevation = vi.fn()
const approveElevation = vi.fn()

vi.mock('@/features/admin/support-access/useAdminSupportAccess', () => ({
  useAdminSupportAccess: () => ({
    overview: supportAccessFixture,
    meta: { current_page: 1, last_page: 2, per_page: 20, total: 21, from: 1, to: 20 },
    isLoading: false,
    error: null,
    requestAccess,
    approveGrant,
    revokeGrant,
    startSession,
    requestElevation,
    approveElevation,
    isMutating: false,
  }),
}))

describe('AdminSupportAccessPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    requestAccess.mockResolvedValue(undefined)
    approveGrant.mockResolvedValue(undefined)
    revokeGrant.mockResolvedValue(undefined)
    requestElevation.mockResolvedValue(undefined)
    approveElevation.mockResolvedValue(undefined)
    localStorage.clear()
    useAuthStore.getState().logout()
    useAdminAuthStore.getState().setAuth(
      { id: 'admin-1', email: 'operator@example.test', name: 'Operator', role: 'super_admin' },
      'admin-memory-token',
    )
  })

  it('submits a complete consent request and exposes grant actions', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: /request access/i }))
    await user.type(screen.getByLabelText(/tenant id/i), '00000000-0000-4000-8000-000000000001')
    await user.type(screen.getByLabelText(/subject user id/i), '00000000-0000-4000-8000-000000000002')
    await user.type(screen.getByLabelText(/support reason/i), 'Investigate missing stock')
    await user.type(screen.getByLabelText(/ticket reference/i), 'SUP-9001')
    await user.clear(screen.getByLabelText(/duration/i))
    await user.type(screen.getByLabelText(/duration/i), '45')
    await user.click(screen.getByRole('button', { name: /send consent request/i }))

    expect(requestAccess).toHaveBeenCalledWith({
      tenant_id: '00000000-0000-4000-8000-000000000001',
      subject_user_id: '00000000-0000-4000-8000-000000000002',
      reason: 'Investigate missing stock',
      ticket_ref: 'SUP-9001',
      duration_minutes: 45,
    })
    expect(screen.queryByRole('button', { name: /second approve/i })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /revoke/i }).length).toBeGreaterThan(0)
  })

  it('starts a subject session without replacing the memory-only admin credential', async () => {
    startSession.mockResolvedValue({
      session_id: 'session-1',
      subject_name: 'Tenant Subject',
      plain_text_token: 'impersonation-token',
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      permissions: ['products.view'],
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: /start session/i }))

    expect(startSession).toHaveBeenCalledWith('grant-active', 'subject-1')
    expect(useAuthStore.getState().token).toBe('impersonation-token')
    expect(useAuthStore.getState().user?.impersonation?.session_id).toBe('session-1')
    expect(useAuthStore.getState().user?.impersonation?.subject_name).toBe('Tenant Subject')
    expect(useAdminAuthStore.getState().token).toBe('admin-memory-token')
    expect(localStorage.getItem('autoerp-auth')).not.toContain('impersonation-token')
  })

  it('shows the active elevation control and expiry state', () => {
    renderPage()
    expect(screen.getByText(/read-only/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /request write access/i })).toBeInTheDocument()
    expect(screen.getAllByText(/expires/i).length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /next/i })).toBeEnabled()
  })

  it('lets the configured distinct approver approve a pending elevation', async () => {
    useAdminAuthStore.getState().setAuth(
      { id: 'approver-1', email: 'approver@example.test', name: 'Partner Approver', role: 'support_approver' },
      'approver-memory-token',
    )
    const user = userEvent.setup()
    renderPage()

    expect(screen.queryByRole('button', { name: /request access/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /start session/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /request write access/i })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /second approve/i })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /approve write elevation/i }))
    expect(approveElevation).toHaveBeenCalledWith('elevation-1')
  })
})

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <AdminSupportAccessPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
