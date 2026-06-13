import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AdminLoginPage } from '../pages/AdminLoginPage'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const navigate = vi.fn()
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))
vi.mock('../api', () => ({
  loginSuperAdmin: vi.fn().mockResolvedValue({
    admin: { id: 'a1', email: 'root@synerivia.test', name: 'Root', role: 'super_admin' },
    token: 'fresh-bearer-token',
  }),
}))

describe('AdminLoginPage', () => {
  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    navigate.mockClear()
  })

  it('stores the bearer token from the login response', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <AdminLoginPage />
      </QueryClientProvider>
    )
    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'root@synerivia.test' } })
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'pw' } })
    fireEvent.click(screen.getByRole('button', { name: /sign in/i }))

    await waitFor(() => {
      expect(useAdminAuthStore.getState().token).toBe('fresh-bearer-token')
    })
    expect(useAdminAuthStore.getState().admin?.email).toBe('root@synerivia.test')
    expect(navigate).toHaveBeenCalledWith('/admin/dashboard')
  })
})
