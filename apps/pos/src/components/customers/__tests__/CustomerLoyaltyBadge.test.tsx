import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string, o?: Record<string, unknown>) => (o ? `${k}:${JSON.stringify(o)}` : k) }) }))
vi.mock('@/stores/cartStore', () => ({ useCartStore: (sel: (s: { total: () => number }) => unknown) => sel({ total: () => 12 }) }))

type Bal = { enrolled: boolean; balance: string; tier: string | null; rate: string | null } | null
const balance: { current: Bal } = { current: { enrolled: true, balance: '340.000', tier: 'Gold', rate: '2' } }
vi.mock('@/lib/loyalty/useLoyaltyBalance', () => ({ useLoyaltyBalance: () => balance.current }))

import { CustomerLoyaltyBadge } from '../CustomerLoyaltyBadge'
const synced = { id: 'p1', phone: '+216200', customer_sync_status: 'synced' } as never

describe('CustomerLoyaltyBadge', () => {
  it('shows tier, balance and the floor(total*rate) estimate when enrolled', () => {
    balance.current = { enrolled: true, balance: '340.000', tier: 'Gold', rate: '2' }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.getByTestId('loyalty-balance')).toHaveTextContent('340')
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24') // floor(12 * 2)
  })

  it('shows the estimate + joins-on-purchase when not enrolled', () => {
    balance.current = { enrolled: false, balance: '0.000', tier: null, rate: '2' }
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-balance')).toBeNull()
    expect(screen.getByTestId('loyalty-estimate')).toHaveTextContent('24')
  })

  it('renders nothing when the balance call returned null (offline/unsynced)', () => {
    balance.current = null
    render(<CustomerLoyaltyBadge customer={synced} />)
    expect(screen.queryByTestId('loyalty-chrome')).toBeNull()
  })
})
