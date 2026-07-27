import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { TopBar } from './TopBar'

vi.mock('../../../stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { name: string } }) => unknown) => selector({ user: { name: 'Test User' } }),
}))
vi.mock('../../../features/auth/useLogout', () => ({ useLogout: () => vi.fn() }))
vi.mock('../../../hooks/useScopeChangeNotice', () => ({ useScopeChangeNotice: vi.fn() }))
vi.mock('../CompanySelector', () => ({ CompanySelector: () => <div /> }))
vi.mock('../ViewScopePicker', () => ({ ViewScopePicker: () => <div /> }))
vi.mock('../../molecules/ConnectionStatusIndicator', () => ({ ConnectionStatusIndicator: () => <div /> }))
vi.mock('./QuickCreateButton', () => ({ QuickCreateButton: () => <div /> }))
vi.mock('../../../features/notifications/components/NotificationBell', () => ({
  NotificationBell: () => <div data-testid="notification-bell" />,
}))
vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }))

describe('TopBar notification wiring', () => {
  it('renders the live notification bell in place of the static indicator', () => {
    render(<TopBar />)

    expect(screen.getByTestId('notification-bell')).toBeInTheDocument()
    expect(screen.queryByLabelText('Notifications')).not.toBeInTheDocument()
  })
})
