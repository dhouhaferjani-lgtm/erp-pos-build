import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { RebalanceAlertsWidget } from '../RebalanceAlertsWidget'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/features/inventory/hooks/useRebalanceSuggestions', () => ({
  useRebalanceSuggestions: () => ({ data: [{ product_id: 'p1', variant_id: null, name: 'Widget', sku: 'W', deficits: [{ location_id: 'l1', available: '2.0000', min_quantity: '5.0000' }], surpluses: [{ location_id: 'l2', available: '8.0000', max_quantity: '5.0000', excess: '3.0000' }] }] }),
}))

describe('RebalanceAlertsWidget', () => {
  it('renders products with both deficit and surplus and links to stock view', () => {
    render(<MemoryRouter><RebalanceAlertsWidget /></MemoryRouter>)
    expect(screen.getByText('Widget')).toBeInTheDocument()
    expect(screen.getByRole('link')).toHaveAttribute('href', '/inventory/stock-by-location')
  })
})
