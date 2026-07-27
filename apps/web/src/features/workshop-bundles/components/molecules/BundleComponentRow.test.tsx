import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BundleComponentRow } from './BundleComponentRow'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('BundleComponentRow', () => {
  it('formats component quantities with the served unit scale', () => {
    render(
      <BundleComponentRow
        currency="TND"
        component={{
          id: 'component-1',
          bundle_id: 'bundle-1',
          component_type: 'part',
          component_id: 'product-1',
          component_display_name: 'Precision part',
          quantity: '1.25',
          quantity_decimals: 3,
          unit: 'kg',
          override_unit_price: null,
          is_optional: false,
          display_order: 0,
          notes: null,
        }}
      />,
    )

    expect(screen.getByText(/1\.250 kg/)).toBeInTheDocument()
  })
})
