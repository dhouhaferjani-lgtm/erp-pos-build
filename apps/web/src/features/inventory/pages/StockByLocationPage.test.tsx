import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StockByLocationPage } from './StockByLocationPage'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/hooks/usePageTitle', () => ({ usePageTitle: vi.fn() }))
vi.mock('@/features/locations/hooks/useViewScope', () => ({ useViewScope: () => ({ scope: 'all', effectiveLocationIds: ['a'], isAll: true }) }))
vi.mock('@/features/locations/hooks/useScopedLocations', () => ({ useScopedLocations: () => ({ data: [{ id: 'a', name: 'Main' }] }) }))
vi.mock('@tanstack/react-query', () => ({ useQuery: () => ({ data: { data: [{ product_id: 'p1', variant_id: null, name: 'Widget', sku: 'W', is_variant_parent: false, cells: { a: { on_hand: '2.0000', reserved: '0.0000', available: '2.0000', min_quantity: null, max_quantity: null } } }], meta: { current_page: 1, last_page: 1, total: 1 } }, isLoading: false, error: null }) }))
// Promoted stock-rebalancing lane (Phase 1.2.5): page-shell tests isolate the independently covered child.
vi.mock('../components/RebalancingView', () => ({ RebalancingView: () => null }))

describe('StockByLocationPage', () => {
  it('renders the stock matrix page and product row', () => {
    render(<StockByLocationPage />)
    expect(screen.getByRole('heading', { name: 'stockByLocation.title' })).toBeInTheDocument()
    expect(screen.getByText('Widget')).toBeInTheDocument()
  })
})
