import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { BundlePicker } from './BundlePicker'
import type { ApplicableBundleData } from '@/features/workshop-bundles/types'

const mockUseApplicableBundles = vi.hoisted(() => vi.fn())
const mockUseBundleExpansion = vi.hoisted(() => vi.fn())

vi.mock('@/features/workshop-bundles/hooks/useBundles', () => ({
  useApplicableBundles: mockUseApplicableBundles,
  useBundleExpansion: mockUseBundleExpansion,
}))

const vidange: ApplicableBundleData = {
  id: 'bbbb0001-0000-4000-8000-000000000001',
  code: 'VIDANGE-10K-ESSENCE',
  name: 'Vidange 10 000 km — Essence',
  description: 'Changement huile + filtre',
  pricing_mode: 'standard',
  base_price: null,
  currency: 'TND',
  service_interval_km: 10000,
  service_interval_months: 12,
  estimated_labor_hours: '0.75',
  component_count: 3,
}
const freinage: ApplicableBundleData = {
  id: 'bbbb0002-0000-4000-8000-000000000002',
  code: 'FREINAGE-AV',
  name: 'Freinage avant',
  description: null,
  pricing_mode: 'standard',
  base_price: null,
  currency: 'TND',
  service_interval_km: null,
  service_interval_months: null,
  estimated_labor_hours: '1',
  component_count: 3,
}

describe('BundlePicker', () => {
  beforeEach(() => {
    mockUseApplicableBundles.mockReset()
    mockUseBundleExpansion.mockReset()
    mockUseBundleExpansion.mockReturnValue({ data: undefined, isLoading: false })
  })

  it('opens on trigger click and lists applicable bundles', async () => {
    mockUseApplicableBundles.mockReturnValue({ data: [vidange, freinage], isLoading: false })
    const user = userEvent.setup()
    renderWithProviders(<BundlePicker value={null} onChange={() => undefined} />)

    await user.click(screen.getByRole('button', { name: /pick a bundle/i }))
    expect(screen.getByText('Vidange 10 000 km — Essence')).toBeInTheDocument()
    expect(screen.getByText('Freinage avant')).toBeInTheDocument()
  })

  it('calls onChange with the chosen bundle after confirming', async () => {
    mockUseApplicableBundles.mockReturnValue({ data: [vidange], isLoading: false })
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<BundlePicker value={null} onChange={onChange} />)

    await user.click(screen.getByRole('button', { name: /pick a bundle/i }))
    await user.click(screen.getByText('Vidange 10 000 km — Essence'))
    await user.click(screen.getByRole('button', { name: /add to work order/i }))

    expect(onChange).toHaveBeenCalledWith(vidange)
  })

  it('renders the selected bundle summary on the trigger when value is set', () => {
    mockUseApplicableBundles.mockReturnValue({ data: [], isLoading: false })
    renderWithProviders(<BundlePicker value={vidange} onChange={() => undefined} />)

    expect(screen.getByText(/VIDANGE-10K-ESSENCE/)).toBeInTheDocument()
    expect(screen.getByText('Vidange 10 000 km — Essence')).toBeInTheDocument()
  })

  it('passes vehicleId to useApplicableBundles so applicability filter runs', async () => {
    mockUseApplicableBundles.mockReturnValue({ data: [vidange], isLoading: false })
    const user = userEvent.setup()
    renderWithProviders(
      <BundlePicker value={null} onChange={() => undefined} vehicleId="vehicle-1" />,
    )
    await user.click(screen.getByRole('button', { name: /pick a bundle/i }))
    expect(mockUseApplicableBundles).toHaveBeenCalledWith(
      expect.objectContaining({ vehicle_id: 'vehicle-1' }),
    )
  })
})
