import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BundleExpandedPreview } from './BundleExpandedPreview'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../../hooks/useBundles', () => ({
  useBundleExpansion: () => ({
    data: [{
      component_type: 'part',
      component_id: 'product-1',
      display_name: 'Precision part',
      quantity: '1.25',
      quantity_decimals: 3,
      unit: 'kg',
      unit_price: '10.000',
      line_total: '12.500',
      is_optional: false,
      is_from_fixed_bundle: false,
    }],
    isLoading: false,
    error: null,
  }),
}))

describe('BundleExpandedPreview', () => {
  it('formats expanded quantities with the served expansion-line scale', () => {
    render(<BundleExpandedPreview bundleId="bundle-1" currency="TND" />)

    expect(screen.getByText(/1\.250 kg/)).toBeInTheDocument()
  })
})
