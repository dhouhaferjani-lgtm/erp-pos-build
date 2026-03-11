import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { RecentVehiclesList } from '../RecentVehiclesList'
import { useVehicleStore } from '../../../stores/useVehicleStore'
import type { SelectedVehicle } from '../../../types/catalog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const translations: Record<string, string> = {
        'parts-catalog:recentVehicles.title': 'Recent Vehicles',
        'parts-catalog:recentVehicles.empty': 'No recent vehicles',
        'parts-catalog:recentVehicles.lastSearched': 'Last searched',
        'parts-catalog:recentVehicles.justNow': 'just now',
        'parts-catalog:recentVehicles.minutesAgo': `${String(opts?.['count'] ?? '')}m ago`,
        'parts-catalog:recentVehicles.hoursAgo': `${String(opts?.['count'] ?? '')}h ago`,
        'parts-catalog:recentVehicles.yesterday': 'yesterday',
        'parts-catalog:recentVehicles.daysAgo': `${String(opts?.['count'] ?? '')}d ago`,
      }
      return translations[key] ?? key
    },
  }),
}))

const createVehicle = (id: string, brand: string, model: string, display: string, date: string): SelectedVehicle => ({
  id,
  display,
  model_series_id: `ms-${id}`,
  vehicle_type: 'pc',
  power_kw: 130,
  power_hp: 177,
  engine_code: 'N47D20A',
  production_from: '200701',
  production_to: '201012',
  manufacturerBrand: brand,
  manufacturerSlug: brand.toLowerCase(),
  modelSeriesName: model,
  selectedAt: date,
})

describe('RecentVehiclesList', () => {
  beforeEach(() => {
    useVehicleStore.setState({ selectedVehicle: null, vehicleHistory: [] })
  })

  it('shows empty message when no history', () => {
    render(<RecentVehiclesList />)
    expect(screen.getByText('No recent vehicles')).toBeInTheDocument()
  })

  it('renders vehicle history items', () => {
    const v1 = createVehicle('v-1', 'BMW', '3 Series (E90)', '320d 2.0 177hp', '2026-03-10T10:00:00Z')
    const v2 = createVehicle('v-2', 'VW', 'Golf VIII', '1.5 TSI 150hp', '2026-03-10T11:00:00Z')
    useVehicleStore.setState({ vehicleHistory: [v2, v1] })

    render(<RecentVehiclesList />)

    expect(screen.getByText('BMW')).toBeInTheDocument()
    expect(screen.getByText('3 Series (E90)')).toBeInTheDocument()
    expect(screen.getByText('VW')).toBeInTheDocument()
    expect(screen.getByText('Golf VIII')).toBeInTheDocument()
  })

  it('selects a vehicle from history on click', () => {
    const v1 = createVehicle('v-1', 'BMW', '3 Series (E90)', '320d 2.0 177hp', '2026-03-10T10:00:00Z')
    useVehicleStore.setState({ vehicleHistory: [v1] })

    render(<RecentVehiclesList />)
    fireEvent.click(screen.getByText('BMW'))

    expect(useVehicleStore.getState().selectedVehicle?.id).toBe('v-1')
  })

  it('shows title', () => {
    render(<RecentVehiclesList />)
    expect(screen.getByText('Recent Vehicles')).toBeInTheDocument()
  })
})
