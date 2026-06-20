import { render, screen, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OwnerDashboardFilters } from '../OwnerDashboardFilters'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))
vi.mock('@/stores/locationStore', () => ({
  useLocationStore: (sel: (s: { locations: { id: string; name: string }[] }) => unknown) =>
    sel({ locations: [{ id: 'loc-1', name: 'Tunis' }, { id: 'loc-2', name: 'Marsa' }] }),
}))

describe('OwnerDashboardFilters location scope', () => {
  it('emits selected location ids', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day', locationIds: [] }} onChange={onChange} />)
    fireEvent.click(screen.getByLabelText('Tunis'))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ locationIds: ['loc-1'] }))
  })

  it('deselects a location when clicked again', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day', locationIds: ['loc-1'] }} onChange={onChange} />)
    fireEvent.click(screen.getByLabelText('Tunis'))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ locationIds: [] }))
  })

  it('resets to all locations when the all-locations button is clicked', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day', locationIds: ['loc-1'] }} onChange={onChange} />)
    fireEvent.click(screen.getByText('reports:ownerDashboard.filters.allLocations'))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ locationIds: [] }))
  })
})
