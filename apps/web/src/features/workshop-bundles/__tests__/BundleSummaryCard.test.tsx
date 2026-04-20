import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { BundleSummaryCard } from '../components/molecules/BundleSummaryCard'
import type { ApplicableBundleData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const baseBundle: ApplicableBundleData = {
  id: 'bundle-1',
  code: 'VIDANGE-10K',
  name: 'Vidange 10k km',
  description: 'Oil change package',
  pricing_mode: 'fixed_bundle',
  base_price: '120.000',
  currency: 'TND',
  service_interval_km: 10000,
  service_interval_months: 12,
  estimated_labor_hours: '0.75',
  component_count: 3,
}

describe('BundleSummaryCard', () => {
  it('renders code, name and description', () => {
    render(<BundleSummaryCard bundle={baseBundle} />)

    expect(screen.getByText('VIDANGE-10K')).toBeInTheDocument()
    expect(screen.getByText('Vidange 10k km')).toBeInTheDocument()
    expect(screen.getByText('Oil change package')).toBeInTheDocument()
  })

  it('calls onSelect when clicked', () => {
    const onSelect = vi.fn()
    render(<BundleSummaryCard bundle={baseBundle} onSelect={onSelect} />)

    const button = screen.getByRole('button')
    fireEvent.click(button)

    expect(onSelect).toHaveBeenCalledWith(baseBundle)
  })

  it('disables click when onSelect is undefined', () => {
    render(<BundleSummaryCard bundle={baseBundle} />)
    const button = screen.getByRole('button')
    expect(button).toBeDisabled()
  })
})
