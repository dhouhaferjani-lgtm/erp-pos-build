import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { CashAcrossStoresWidget } from '../CashAcrossStoresWidget'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/features/treasury/hooks/useCashPosition', () => ({
  useCashPosition: () => ({ data: { currency: 'TND', grand_total: '150.000', groups_by_location: [{ location_id: 'l1', location_name: 'Store 1', total: '150.000' }] }, isError: false }),
}))

describe('CashAcrossStoresWidget', () => {
  it('renders location groups, total, and deep link', () => {
    render(<MemoryRouter><CashAcrossStoresWidget /></MemoryRouter>)
    expect(screen.getByText('Store 1')).toBeInTheDocument()
    expect(screen.getByText(/cashAcrossStores.total/)).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/finance/overview')
  })
})
