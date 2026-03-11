import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { StickyVehicleBar } from '../StickyVehicleBar'
import { useVehicleStore } from '../../../stores/useVehicleStore'
import type { SelectedVehicle } from '../../../types/catalog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const o = opts ?? {}
      const translations: Record<string, string> = {
        'parts-catalog:stickyBar.change': 'Change',
        'parts-catalog:stickyBar.clear': 'Clear',
        'parts-catalog:stickyBar.clearConfirmTitle': 'Clear Vehicle?',
        'parts-catalog:stickyBar.clearConfirmMessage': 'This will clear your search results. Continue?',
        'parts-catalog:stickyBar.confirm': 'Yes, Clear',
        'parts-catalog:stickyBar.cancel': 'Cancel',
        'parts-catalog:vehicle.power': `${String(o['kw'] ?? '')} kW / ${String(o['hp'] ?? '')} hp`,
        'parts-catalog:vehicle.engineCode': `Engine: ${String(o['code'] ?? '')}`,
        'parts-catalog:vehicle.productionYears': `${String(o['from'] ?? '')} – ${String(o['to'] ?? '')}`,
      }
      return translations[key] ?? key
    },
  }),
}))

const mockVehicle: SelectedVehicle = {
  id: 'v-1',
  display: '320d 2.0 177hp',
  model_series_id: 'ms-1',
  vehicle_type: 'pc',
  power_kw: 130,
  power_hp: 177,
  engine_code: 'N47D20A',
  production_from: '200701',
  production_to: '201012',
  manufacturerBrand: 'BMW',
  manufacturerSlug: 'bmw',
  modelSeriesName: '3 Series (E90)',
  selectedAt: '2026-03-10T10:00:00Z',
}

describe('StickyVehicleBar', () => {
  beforeEach(() => {
    useVehicleStore.setState({ selectedVehicle: null, vehicleHistory: [] })
  })

  it('renders nothing when no vehicle is selected', () => {
    const { container } = render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)
    expect(container.firstChild).toBeNull()
  })

  it('renders vehicle info when a vehicle is selected', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    expect(screen.getByText('BMW')).toBeInTheDocument()
    expect(screen.getByText('3 Series (E90)')).toBeInTheDocument()
    expect(screen.getByText('320d 2.0 177hp')).toBeInTheDocument()
  })

  it('shows power and engine code details', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    expect(screen.getByText('130 kW / 177 hp')).toBeInTheDocument()
    expect(screen.getByText('Engine: N47D20A')).toBeInTheDocument()
  })

  it('calls onChangeVehicle when Change button clicked', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle })
    const onChangeVehicle = vi.fn()
    render(<StickyVehicleBar onChangeVehicle={onChangeVehicle} />)

    fireEvent.click(screen.getByText('Change'))
    expect(onChangeVehicle).toHaveBeenCalledOnce()
  })

  it('shows confirmation dialog when Clear clicked', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    fireEvent.click(screen.getByText('Clear'))
    expect(screen.getByText('Clear Vehicle?')).toBeInTheDocument()
    expect(screen.getByText('This will clear your search results. Continue?')).toBeInTheDocument()
  })

  it('clears vehicle when confirmation is accepted', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle, vehicleHistory: [mockVehicle] })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    fireEvent.click(screen.getByText('Clear'))
    fireEvent.click(screen.getByText('Yes, Clear'))

    expect(useVehicleStore.getState().selectedVehicle).toBeNull()
  })

  it('cancels clear when Cancel is clicked', () => {
    useVehicleStore.setState({ selectedVehicle: mockVehicle })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    fireEvent.click(screen.getByText('Clear'))
    fireEvent.click(screen.getByText('Cancel'))

    expect(useVehicleStore.getState().selectedVehicle).toEqual(mockVehicle)
  })

  it('shows plate when available', () => {
    useVehicleStore.setState({
      selectedVehicle: { ...mockVehicle, plate: 'AB-123-CD' },
    })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    expect(screen.getByText('AB-123-CD')).toBeInTheDocument()
  })

  it('shows VIN when available', () => {
    useVehicleStore.setState({
      selectedVehicle: { ...mockVehicle, vin: 'WBAPH5C55BA123456' },
    })
    render(<StickyVehicleBar onChangeVehicle={vi.fn()} />)

    expect(screen.getByText('WBAPH5C55BA123456')).toBeInTheDocument()
  })
})
