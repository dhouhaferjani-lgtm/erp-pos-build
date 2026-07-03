import { render, screen, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnerDashboardFilters } from '../OwnerDashboardFilters'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))
vi.mock('@/stores/locationStore', () => ({
  useLocationStore: (sel: (s: { locations: { id: string; name: string }[] }) => unknown) =>
    sel({ locations: [{ id: 'loc-1', name: 'Tunis' }, { id: 'loc-2', name: 'Marsa' }] }),
}))

describe('OwnerDashboardFilters location scope', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-03T10:15:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

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

  it('emits today, seven-day, and thirty-day quick presets', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day', locationIds: ['loc-1'] }} onChange={onChange} />)

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.today' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour', locationIds: ['loc-1'] })

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.last7Days' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-06-27', to: '2026-07-03', granularity: 'day', locationIds: ['loc-1'] })

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.last30Days' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-06-04', to: '2026-07-03', granularity: 'day', locationIds: ['loc-1'] })
  })

  it('auto-selects hour when manual date edits make the range a single day', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-07-03', to: '2026-07-04', granularity: 'day', locationIds: [] }} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/reports:ownerDashboard.filters.to/), { target: { value: '2026-07-03' } })

    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour', locationIds: [] })
  })

  it('allows the user to override a single-day range back to day granularity', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-07-03', to: '2026-07-03', granularity: 'hour', locationIds: [] }} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/reports:ownerDashboard.filters.granularity/), { target: { value: 'day' } })

    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'day', locationIds: [] })
  })
})
