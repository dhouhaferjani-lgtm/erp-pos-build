import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { NotificationBell } from './NotificationBell'

const mockUseUnreadNotificationCount = vi.hoisted(() => vi.fn())

vi.mock('../hooks/useNotifications', () => ({
  useUnreadNotificationCount: mockUseUnreadNotificationCount,
}))

vi.mock('./NotificationPanel', () => ({
  NotificationPanel: () => <div data-testid="notification-panel" />,
}))

describe('NotificationBell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('hides the badge when the unread count is zero', () => {
    mockUseUnreadNotificationCount.mockReturnValue({ data: { count: 0 } })

    render(<NotificationBell />)

    expect(screen.queryByTestId('notification-count')).not.toBeInTheDocument()
  })

  it('shows the unread count and opens the panel', async () => {
    const user = userEvent.setup()
    mockUseUnreadNotificationCount.mockReturnValue({ data: { count: 7 } })

    render(<NotificationBell />)

    expect(screen.getByTestId('notification-count')).toHaveTextContent('7')
    expect(screen.queryByTestId('notification-panel')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /notifications/i }))

    expect(screen.getByTestId('notification-panel')).toBeInTheDocument()
  })
})
