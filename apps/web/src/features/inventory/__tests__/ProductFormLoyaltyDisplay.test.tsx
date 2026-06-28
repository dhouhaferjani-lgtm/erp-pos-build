import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'

// State variable mutated per-test to control the mock return value.
let mockRate: string | null = '2'

// Mock the earn-rate hook so the test is hermetic.
vi.mock('../useLoyaltyEarnRate', () => ({
  useLoyaltyEarnRate: () => ({ rate: mockRate, isLoading: false }),
}))

// Mock useCompanyConfig – Loyalty module always on for this suite.
vi.mock('../../../contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ config: null, hasModule: (m: string) => m === 'Loyalty' }),
}))

// i18n: echo the key so assertions are stable regardless of catalog state.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

import { LoyaltyPointsDisplay } from '../LoyaltyPointsDisplay'

describe('LoyaltyPointsDisplay', () => {
  it('shows derived points = salePrice * rate when the module is on', () => {
    mockRate = '2'
    render(<LoyaltyPointsDisplay salePrice="12.000" />)
    expect(screen.getByTestId('loyalty-derived-points')).toHaveTextContent('24')
  })

  it('renders nothing when there is no rate', () => {
    mockRate = null
    const { container } = render(<LoyaltyPointsDisplay salePrice="12.000" />)
    expect(container.firstChild).toBeNull()
  })
})
