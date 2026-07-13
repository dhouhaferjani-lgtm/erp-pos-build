import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AppNotification } from '../api/notificationsApi'
import { NotificationPanel } from './NotificationPanel'
import { formatCurrency } from '@/lib/format'

const mockNavigate = vi.hoisted(() => vi.fn())
const mockMutateAll = vi.hoisted(() => vi.fn())
const mockMutateOne = vi.hoisted(() => vi.fn())
const mockUseNotificationsList = vi.hoisted(() => vi.fn())

vi.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }))
vi.mock('../hooks/useNotifications', () => ({
  useMarkAllNotificationsRead: () => ({ isPending: false, mutateAsync: mockMutateAll }),
  useMarkNotificationRead: () => ({ isPending: false, mutateAsync: mockMutateOne }),
  useNotificationsList: mockUseNotificationsList,
}))

const notifications: AppNotification[] = [
  {
    id: 'older-read',
    type: 'treasury.instrument.maturity_alert',
    data: { company_name: 'Northwind', received_due_count: 1, deposited_overdue_count: 0 },
    read_at: '2026-07-12T08:30:00Z',
    created_at: '2026-07-12T08:00:00Z',
  },
  {
    id: 'newer-unread',
    type: 'treasury.reconcile.drift',
    data: { message: 'Repository CASH-01 needs attention', deep_link: '/treasury/repositories/repo-1' },
    read_at: null,
    created_at: '2026-07-12T10:00:00Z',
  },
]

function setList(data: AppNotification[]) {
  mockUseNotificationsList.mockReturnValue({
    data: {
      data,
      meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
    },
    isError: false,
    isLoading: false,
  })
}

describe('NotificationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMutateOne.mockResolvedValue(undefined)
    mockMutateAll.mockResolvedValue(undefined)
    setList(notifications)
  })

  it('renders newest first and exposes unread state', () => {
    render(<NotificationPanel />)

    expect(screen.getByRole('dialog').tagName).toBe('DIALOG')
    const rows = within(screen.getByRole('list')).getAllByRole('listitem')
    expect(rows[0]).toHaveTextContent('Repository CASH-01 needs attention')
    expect(rows[0]).toHaveAttribute('data-read-state', 'unread')
    expect(rows[1]).toHaveAttribute('data-read-state', 'read')
  })

  it('marks an unread item read before following its deep link', async () => {
    const user = userEvent.setup()
    render(<NotificationPanel />)

    await user.click(screen.getByRole('button', { name: /Repository CASH-01 needs attention/i }))

    expect(mockMutateOne).toHaveBeenCalledWith('newer-unread')
    expect(mockNavigate).toHaveBeenCalledWith('/treasury/repositories/repo-1')
    expect(mockMutateOne.mock.invocationCallOrder[0]).toBeLessThan(mockNavigate.mock.invocationCallOrder[0])
  })

  it('does not mark an already-read item again', async () => {
    const user = userEvent.setup()
    render(<NotificationPanel />)

    const rows = within(screen.getByRole('list')).getAllByRole('listitem')
    await user.click(within(rows[1]).getByRole('button'))

    expect(mockMutateOne).not.toHaveBeenCalled()
  })

  it('marks every notification read', async () => {
    const user = userEvent.setup()
    render(<NotificationPanel />)

    await user.click(screen.getByRole('button', { name: /mark all as read/i }))

    expect(mockMutateAll).toHaveBeenCalledOnce()
  })

  it('renders the empty state', () => {
    setList([])

    render(<NotificationPanel />)

    expect(screen.getByText('No notifications yet')).toBeInTheDocument()
  })

  it('renders a legacy PHP FQCN notification through the generic fallback', () => {
    setList([{
      id: 'legacy-1',
      type: 'App\\Notifications\\BatchExpiryNotification',
      data: { message: 'Lot B-42 expires tomorrow' },
      read_at: null,
      created_at: '2026-07-12T11:00:00Z',
    }])

    render(<NotificationPanel />)

    expect(screen.getByText('Notification')).toBeInTheDocument()
    expect(screen.getByText('App\\Notifications\\BatchExpiryNotification')).toBeInTheDocument()
    expect(screen.getByText('Lot B-42 expires tomorrow')).toBeInTheDocument()
  })

  it('renders a generated recurring expense with formatted amount and follows its deep link', async () => {
    const user = userEvent.setup()
    setList([{
      id: 'recurring-1',
      type: 'expense.recurring.generated',
      data: {
        template_name: 'Tunis office rent',
        amount: '1250.000',
        currency: 'TND',
        due_date: '2026-08-31',
        deep_link: '/expenses/expense-1/view',
      },
      read_at: null,
      created_at: '2026-07-12T12:00:00Z',
    }])

    render(<NotificationPanel />)

    expect(screen.getByText('Recurring expense generated')).toBeInTheDocument()
    const amount = formatCurrency('1250.000', { currency: 'TND' })
    const message = screen.getByText(/Tunis office rent generated a draft expense/)
    expect(message.textContent).toContain(amount)
    expect(screen.queryByText(/\{\{/)).not.toBeInTheDocument()
    expect(screen.queryByText('expense.recurring.generated')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Tunis office rent/i }))
    expect(mockNavigate).toHaveBeenCalledWith('/expenses/expense-1/view')
  })
})
