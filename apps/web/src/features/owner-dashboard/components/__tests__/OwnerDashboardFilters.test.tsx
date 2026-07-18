import { render, screen, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnerDashboardFilters } from '../OwnerDashboardFilters'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))
describe('OwnerDashboardFilters', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-03T10:15:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('emits today, seven-day, and thirty-day quick presets', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-06-01', to: '2026-06-30', granularity: 'day' }} onChange={onChange} />)

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.today' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour' })

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.last7Days' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-06-27', to: '2026-07-03', granularity: 'day' })

    fireEvent.click(screen.getByRole('button', { name: 'reports:ownerDashboard.filters.last30Days' }))
    expect(onChange).toHaveBeenCalledWith({ from: '2026-06-04', to: '2026-07-03', granularity: 'day' })
  })

  it('auto-selects hour when manual date edits make the range a single day', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-07-03', to: '2026-07-04', granularity: 'day' }} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/reports:ownerDashboard.filters.to/), { target: { value: '2026-07-03' } })

    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'hour' })
  })

  it('allows the user to override a single-day range back to day granularity', () => {
    const onChange = vi.fn()
    render(<OwnerDashboardFilters value={{ from: '2026-07-03', to: '2026-07-03', granularity: 'hour' }} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/reports:ownerDashboard.filters.granularity/), { target: { value: 'day' } })

    expect(onChange).toHaveBeenCalledWith({ from: '2026-07-03', to: '2026-07-03', granularity: 'day' })
  })
})
