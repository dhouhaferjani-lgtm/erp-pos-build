import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { UserEditModal } from './UserEditModal'

const updateUser = vi.hoisted(() => vi.fn())
const useManagementLocations = vi.hoisted(() => vi.fn())

vi.mock('@/features/users/api/users', () => ({ updateUser }))
vi.mock('@/features/locations/hooks/useManagementLocations', () => ({ useManagementLocations }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

const roles = [{ name: 'operator', permissions: [] }]
const baseUser = { id: 'u-other', name: 'Other', email: 'other@test.test', phone: null, roles: ['operator'], status: 'active' as const, lastLoginAt: null, createdAt: '2026-01-01T00:00:00Z', allowed_location_ids: null }

function renderModal(user = baseUser) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <UserEditModal user={user} roles={roles} onClose={vi.fn()} onSuccess={vi.fn()} onError={vi.fn()} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  useManagementLocations.mockReturnValue({ data: [{ id: 'loc-a', name: 'Main', code: 'HQ', type: 'shop', isDefault: true }], isLoading: false })
  updateUser.mockResolvedValue(baseUser)
  useAuthStore.setState({ user: { id: 'u-current', name: 'Admin', email: 'admin@test.test', tenant_id: 'tenant-1', roles: ['admin'], email_verified_at: null }, token: 'token', isAuthenticated: true, isLoading: false })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
})

describe('UserEditModal location access', () => {
  it('sends the selected assignment for another user', async () => {
    renderModal()
    fireEvent.click(screen.getByRole('radio', { name: /locations:staffAccess\.subset/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: /Main/ }))
    fireEvent.click(screen.getByRole('button', { name: 'settings:userEdit.save' }))
    await waitFor(() => expect(updateUser).toHaveBeenCalledWith('u-other', expect.objectContaining({ allowed_location_ids: ['loc-a'] })))
  })

  it('disables the field and omits assignment when editing own row', async () => {
    renderModal({ ...baseUser, id: 'u-current' })
    expect(screen.getByText('locations:staffAccess.selfDisabledHint')).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /locations:staffAccess\.allLocations/ })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'settings:userEdit.save' }))
    await waitFor(() => expect(updateUser).toHaveBeenCalledWith('u-current', expect.not.objectContaining({ allowed_location_ids: expect.anything() })))
  })
})
