import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { RebalancingView } from './RebalancingView'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({ scope: 'all', effectiveLocationIds: ['source', 'target'] }),
}))

vi.mock('@/features/locations/hooks/useScopedLocations', () => ({
  useScopedLocations: () => ({
    data: [
      { id: 'source', name: 'Source' },
      { id: 'target', name: 'Target' },
    ],
  }),
}))

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({
    data: {
      data: [{
        product_id: 'product-1',
        variant_id: null,
        name: 'Weighted Product',
        sku: 'WEIGHTED',
        quantity_decimals: 3,
        deficits: [{ location_id: 'target', available: '0.0000', min_quantity: null }],
        surpluses: [{ location_id: 'source', available: '5.0000', max_quantity: null, excess: '5.0000' }],
      }],
    },
    isLoading: false,
  }),
}))

describe('RebalancingView', () => {
  it('renders a move once at the product unit precision', () => {
    render(<RebalancingView />)

    expect(screen.getByText('Source → Target · 5.000')).toBeInTheDocument()
  })
})
