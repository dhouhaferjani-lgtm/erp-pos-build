import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAdminAuthStore } from '@/features/admin/stores/adminAuthStore'
import { useAuthStore } from '@/stores/authStore'
import { ImpersonationBanner } from '../components/ImpersonationBanner'

const { exitSession, toastError } = vi.hoisted(() => ({
  exitSession: vi.fn().mockResolvedValue(undefined),
  toastError: vi.fn(),
}))
vi.mock('../api/tenantSupportAccessApi', () => ({ exitImpersonationSession: exitSession }))
vi.mock('sonner', () => ({ toast: { error: toastError } }))

describe('ImpersonationBanner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    exitSession.mockResolvedValue(undefined)
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const expires = new Date(Date.now() + 301_000).toISOString()
    useAuthStore.getState().setAuth({
      id: 'subject-1', name: 'Tenant Subject', email: 'subject@test', tenant_id: 'tenant-1',
      roles: ['admin'], permissions: ['products.view'], email_verified_at: null,
      impersonation: {
        session_id: 'session-1', subject_user_id: 'subject-1', subject_name: 'Tenant Subject',
        reason: 'Investigate stock mismatch', ticket_ref: 'SUP-9000', access_level: 'read_only',
        expires_at: expires, remaining_seconds: 301,
      },
    }, 'impersonation-token')
    useAdminAuthStore.getState().setAuth(
      { id: 'admin-1', email: 'operator@test', name: 'Operator', role: 'super_admin' },
      'admin-memory-token',
    )
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('is persistent, non-dismissible, and warns when five minutes remain', () => {
    renderBanner()
    expect(screen.getByRole('region', { name: /Tenant Subject/i })).toHaveTextContent('Tenant Subject')
    expect(screen.getByRole('region', { name: /Tenant Subject/i })).toHaveTextContent('SUP-9000')
    expect(screen.getByRole('region', { name: /Tenant Subject/i })).toHaveTextContent(/read-only/i)
    expect(screen.getByRole('timer')).toHaveAttribute('aria-live', 'off')
    expect(screen.queryByRole('button', { name: /dismiss|close/i })).not.toBeInTheDocument()

    act(() => { vi.advanceTimersByTime(2_000) })
    expect(screen.getByText(/ending soon/i)).toBeInTheDocument()
  })

  it('exits, clears tenant state, preserves admin auth, and returns to support queue', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    renderBanner()
    await user.click(screen.getByRole('button', { name: /exit support session/i }))

    expect(exitSession).toHaveBeenCalledWith('session-1')
    expect(useAuthStore.getState().user).toBeNull()
    expect(useAdminAuthStore.getState().token).toBe('admin-memory-token')
    await waitFor(() => { expect(screen.getByText('Admin support queue')).toBeInTheDocument() })
  })

  it('keeps the live subject credential when the server exit fails', async () => {
    exitSession.mockRejectedValueOnce(new Error('exit unavailable'))
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
    renderBanner()

    await user.click(screen.getByRole('button', { name: /exit support session/i }))

    expect(useAuthStore.getState().token).toBe('impersonation-token')
    expect(useAuthStore.getState().user?.impersonation?.session_id).toBe('session-1')
    expect(toastError).toHaveBeenCalled()
  })

  it('clears the expired subject credential automatically', async () => {
    const current = useAuthStore.getState().user
    if (!current?.impersonation) throw new Error('Missing impersonation fixture')
    useAuthStore.getState().setUser({
      ...current,
      impersonation: {
        ...current.impersonation,
        expires_at: new Date(Date.now() + 1_000).toISOString(),
        remaining_seconds: 1,
      },
    })
    renderBanner()

    act(() => { vi.advanceTimersByTime(2_000) })

    await waitFor(() => { expect(useAuthStore.getState().user).toBeNull() })
    expect(screen.getByText('Admin support queue')).toBeInTheDocument()
  })
})

function renderBanner() {
  const client = new QueryClient()
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route path="/dashboard" element={<ImpersonationBanner />} />
          <Route path="/admin/support-access" element={<div>Admin support queue</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
