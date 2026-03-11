import { describe, it, expect, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useVehicleStore } from '../useVehicleStore'
import type { SelectedVehicle } from '../../types/catalog'

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

const mockVehicle2: SelectedVehicle = {
  id: 'v-2',
  display: 'Golf VIII 1.5 TSI 150hp',
  model_series_id: 'ms-2',
  vehicle_type: 'pc',
  power_kw: 110,
  power_hp: 150,
  engine_code: 'DADA',
  production_from: '201901',
  production_to: null,
  manufacturerBrand: 'Volkswagen',
  manufacturerSlug: 'volkswagen',
  modelSeriesName: 'Golf VIII',
  selectedAt: '2026-03-10T11:00:00Z',
}

describe('useVehicleStore', () => {
  beforeEach(() => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.clearVehicle()
      // Clear history by selecting and clearing repeatedly won't work,
      // so we reset the store directly
      useVehicleStore.setState({ selectedVehicle: null, vehicleHistory: [] })
    })
  })

  it('starts with no selected vehicle and empty history', () => {
    const { result } = renderHook(() => useVehicleStore())
    expect(result.current.selectedVehicle).toBeNull()
    expect(result.current.vehicleHistory).toHaveLength(0)
  })

  it('selects a vehicle', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.selectVehicle(mockVehicle)
    })
    expect(result.current.selectedVehicle).toEqual(mockVehicle)
  })

  it('adds selected vehicle to history', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.selectVehicle(mockVehicle)
    })
    expect(result.current.vehicleHistory).toHaveLength(1)
    expect(result.current.vehicleHistory[0].id).toBe('v-1')
  })

  it('does not duplicate vehicles in history', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.selectVehicle(mockVehicle)
      result.current.selectVehicle(mockVehicle2)
      result.current.selectVehicle(mockVehicle) // re-select first
    })
    expect(result.current.vehicleHistory).toHaveLength(2)
    // Most recent first
    expect(result.current.vehicleHistory[0].id).toBe('v-1')
    expect(result.current.vehicleHistory[1].id).toBe('v-2')
  })

  it('limits history to 10 vehicles', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      for (let i = 0; i < 12; i++) {
        result.current.selectVehicle({
          ...mockVehicle,
          id: `v-${String(i)}`,
          selectedAt: new Date(Date.now() + i * 1000).toISOString(),
        })
      }
    })
    expect(result.current.vehicleHistory).toHaveLength(10)
    // Most recent should be first
    expect(result.current.vehicleHistory[0].id).toBe('v-11')
  })

  it('clears the selected vehicle', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.selectVehicle(mockVehicle)
    })
    expect(result.current.selectedVehicle).not.toBeNull()
    act(() => {
      result.current.clearVehicle()
    })
    expect(result.current.selectedVehicle).toBeNull()
    // History should remain
    expect(result.current.vehicleHistory).toHaveLength(1)
  })

  it('selectFromHistory sets vehicle as selected and moves to front of history', () => {
    const { result } = renderHook(() => useVehicleStore())
    act(() => {
      result.current.selectVehicle(mockVehicle)
      result.current.selectVehicle(mockVehicle2)
    })
    // mockVehicle2 is most recent
    expect(result.current.vehicleHistory[0].id).toBe('v-2')

    act(() => {
      result.current.selectFromHistory('v-1')
    })
    expect(result.current.selectedVehicle?.id).toBe('v-1')
    expect(result.current.vehicleHistory[0].id).toBe('v-1')
  })
})
