import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { ProductLocationMatrix } from './ProductLocationMatrix'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/auth', () => ({ RequirePermission: ({ children }: { children: React.ReactNode }) => <>{children}</> }))

const row = {
  product_id: 'p1', variant_id: null, name: 'Widget', sku: 'W-1', is_variant_parent: true,
  cells: {
    a: { on_hand: '2.0000', reserved: '0.0000', available: '2.0000', min_quantity: '3.0000', max_quantity: '9.0000' },
  },
}

describe('ProductLocationMatrix', () => {
  it('renders one column per location and formatted quantity', () => {
    render(<ProductLocationMatrix rows={[row]} locations={[{ id: 'a', name: 'Main' }]} />)
    expect(screen.getByText('Main')).toBeInTheDocument()
    expect(screen.getAllByText('2').length).toBeGreaterThan(0)
  })

  it('switches metric and expands variant children', async () => {
    const child = { ...row, variant_id: 'v1', name: 'Widget Red', is_variant_parent: false }
    render(<ProductLocationMatrix rows={[row, child]} locations={[{ id: 'a', name: 'Main' }]} />)
    expect(screen.queryByText('Widget Red')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Widget/ }))
    expect(screen.getByText('Widget Red')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'stockByLocation.metric.onHand' }))
    expect(screen.getAllByText('2').length).toBeGreaterThan(0)
  })
})
