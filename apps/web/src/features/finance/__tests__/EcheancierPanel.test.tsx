import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'

import { EcheancierPanel } from '../components/EcheancierPanel'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('react-router-dom', () => ({ Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a> }))
vi.mock('@/features/treasury/hooks/useMaturingInstruments', () => ({
  useMaturingInstruments: () => ({
    from: '2026-07-11',
    to: '2026-08-10',
    isLoading: false,
    error: null,
    data: {
      data: [
        { id: 'i-1', reference: 'EFF-1', amount: '10.000', currency: 'TND', maturity_date: '2026-07-15', direction: 'inbound', certainty: 'portfolio' },
        { id: 'i-2', reference: 'CHK-2', amount: '9.000', currency: 'TND', maturity_date: '2026-07-20', direction: 'outbound', certainty: 'remitted' },
      ],
      meta: { buckets: {
        overdue: { count: 0, total_in: '0.000', total_out: '0.000' },
        d0_7: { count: 1, total_in: '10.000', total_out: '0.000' },
        d8_30: { count: 1, total_in: '5.000', total_out: '9.000' },
        d31_60: { count: 0, total_in: '0.000', total_out: '0.000' },
        d61_90: { count: 0, total_in: '0.000', total_out: '0.000' },
        d90_plus: { count: 0, total_in: '0.000', total_out: '0.000' },
      }, grand_total: { count: 2, total_in: '15.000', total_out: '9.000' } },
    },
  }),
}))

describe('EcheancierPanel', () => {
  it('shows next-30-day in/out totals, top rows, and a filtered register link', () => {
    render(<EcheancierPanel from="2026-07-11" to="2026-08-10" formatMoney={(amount) => `${amount} TND`} />)

    expect(screen.getByText('finance:overview.echeancier.title')).toBeInTheDocument()
    expect(screen.getByText('15.000 TND')).toBeInTheDocument()
    expect(screen.getAllByText('9.000 TND').length).toBeGreaterThan(0)
    expect(screen.getByText('EFF-1')).toBeInTheDocument()
    expect(screen.getByText('CHK-2')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /finance:overview.echeancier.openRegister/ })).toHaveAttribute('href', '/treasury/instruments?maturity_from=2026-07-11&maturity_to=2026-08-10')
  })
})
