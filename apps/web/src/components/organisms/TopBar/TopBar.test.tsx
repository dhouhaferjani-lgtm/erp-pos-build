import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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

// Regression test for docs/superpowers/tickets/2026-08-02-topbar-dropdown-unclickable-zindex.md:
// both TopBar dropdowns are `position: absolute` siblings that precede DashboardLayout's
// `<main className="relative ...">`. With z-index:auto, DOM order lets <main> win the
// stacking order and swallow pointer events aimed at the dropdown. These dropdowns must
// carry an explicit stacking-context z-index (matching the z-50 already used by the
// other TopBar-family dropdowns: CompanySelector, ViewScopePicker, QuickCreateButton).
describe('TopBar dropdown stacking context', () => {
  it('gives the user-menu dropdown an explicit z-index above <main>', async () => {
    const user = userEvent.setup()
    render(<TopBar />)

    await user.click(screen.getByRole('button', { name: /profile/i }))

    const signOut = screen.getByRole('button', { name: /sign out/i })
    const dropdown = signOut.closest('div')
    expect(dropdown).not.toBeNull()
    expect(dropdown?.className).toMatch(/\bz-50\b/)
  })

  it('gives the language dropdown an explicit z-index above <main>', async () => {
    const user = userEvent.setup()
    render(<TopBar />)

    await user.click(screen.getByRole('button', { name: /select language/i }))

    const frenchOption = screen.getByText('Français', { exact: true })
    const dropdown = frenchOption.closest('div')
    expect(dropdown).not.toBeNull()
    expect(dropdown?.className).toMatch(/\bz-50\b/)
  })
})
