import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { UserEditModal } from './UserEditModal'
import type { User } from '../../users/types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('../../users/api/users', () => ({
  updateUser: vi.fn(),
}))

vi.mock('../../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../../lib/api')>('../../../lib/api')
  return { ...actual, getErrorMessage: () => 'request failed' }
})

const user: User = {
  id: 'user-1',
  name: 'Jane Cashier',
  email: 'jane@example.test',
  phone: null,
  status: 'active',
  roles: ['manager'],
  lastLoginAt: null,
  createdAt: '2026-01-01T00:00:00Z',
}

const roles = [
  { name: 'manager', permissions: [] },
  { name: 'cashier', permissions: [] },
]

function renderModal() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
  return render(
    <UserEditModal
      user={user}
      roles={roles}
      onClose={vi.fn()}
      onSuccess={vi.fn()}
      onError={vi.fn()}
    />,
    { wrapper: Wrapper },
  )
}

describe('UserEditModal shared primitives', () => {
  it('renders inside the Modal organism with role="dialog"', () => {
    renderModal()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('renders the name/email/role form fields', () => {
    renderModal()
    expect(screen.getByLabelText(/common:users\.modal\.nameLabel/)).toHaveValue('Jane Cashier')
    expect(screen.getByLabelText(/common:users\.modal\.emailLabel/)).toHaveValue('jane@example.test')
    expect(screen.getByLabelText(/common:users\.modal\.roleLabel/)).toBeInTheDocument()
  })

  it('renders a primary submit button', () => {
    renderModal()
    expect(screen.getByRole('button', { name: /settings:userEdit\.save/ })).toBeInTheDocument()
  })
})
