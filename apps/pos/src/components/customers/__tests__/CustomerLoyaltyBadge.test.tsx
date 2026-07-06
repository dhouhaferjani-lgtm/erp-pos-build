import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string, o?: Record<string, unknown>) => (o ? `${k}:${JSON.stringify(o)}` : k) }) }))
vi.mock('@/stores/cartStore', () => ({ useCartStore: (sel: (s: { total: () => number }) => unknown) => sel({ total: () => 12 }) }))

type Bal = { enrolled: boolean; balance: string; tier: string | null; rate: string | null } | null
const balance: { current: Bal } = { current: { enrolled: true, balance: '340.000', tier: 'Gold', rate: '2' } }
const refresh = vi.fn()
vi.mock('@/lib/loyalty/useLoyaltyBalance', () => ({ useLoyaltyBalance: () => ({ balance: balance.current, refresh }) }))

const enrollLoyalty = vi.fn()
vi.mock('@/lib/loyalty/loyaltyApi', () => ({ enrollLoyalty: (...args: unknown[]) => enrollLoyalty(...args) }))

import { CustomerLoyaltyBadge } from '../CustomerLoyaltyBadge'
const synced = { id: 'p1', phone: '+216200', customer_sync_status: 'synced' } as never

describe('CustomerLoyaltyBadge', () => {
  beforeEach(() => {
    refresh.mockClear()
    enrollLoyalty.mockReset()
  })

  it('shows tier, balance and the floor(total*rate) estimate when enrolled', () => {
    balance.current = { enrolled: true, balance: '340.000', tier: 'Gold', rate: '2' }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.getByTestId('loyalty-balance')).toHaveTextContent('340')
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24') // floor(12 * 2)
    expect(screen.queryByText('loyalty.enroll')).toBeNull()
  })

  it('shows an Enroll button (not inert text) when not enrolled and rate is set', () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null, rate: '2' }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24')
    expect(screen.getByText('loyalty.enroll')).toBeInTheDocument()
    expect(screen.queryByText('loyalty.joinsOnPurchase')).toBeNull()
  })

  it('renders nothing when the balance call returned null (offline/unsynced)', () => {
    balance.current = null
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-chrome')).toBeNull()
  })

  it('renders nothing when not enrolled and rate is null (no active program)', () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null, rate: null }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-chrome')).toBeNull()
    expect(screen.queryByText('loyalty.enroll')).toBeNull()
  })

  it('opens the enroll dialog, submits the typed phone, and refreshes on success', async () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null, rate: '2' }
    enrollLoyalty.mockResolvedValue({ enrolled: true, balance: '0.000', tier: null, rate: '2' })
    render(<CustomerLoyaltyBadge customer={synced} />)

    fireEvent.click(screen.getByText('loyalty.enroll'))
    const phoneInput = screen.getByLabelText('loyalty.enrollPhoneLabel')
    expect(phoneInput).toHaveValue('+216200')
    fireEvent.change(phoneInput, { target: { value: '+21699999999' } })
    fireEvent.click(screen.getByText('loyalty.enrollSubmit'))

    await waitFor(() => expect(enrollLoyalty).toHaveBeenCalledWith('p1', '+21699999999'))
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1))
  })

  it('shows an inline error and does not refresh when enrollment fails', async () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null, rate: '2' }
    enrollLoyalty.mockRejectedValue(new Error('network down'))
    render(<CustomerLoyaltyBadge customer={synced} />)

    fireEvent.click(screen.getByText('loyalty.enroll'))
    fireEvent.click(screen.getByText('loyalty.enrollSubmit'))

    await waitFor(() => expect(screen.getByText('loyalty.enrollFailed')).toBeInTheDocument())
    expect(refresh).not.toHaveBeenCalled()
  })
})
