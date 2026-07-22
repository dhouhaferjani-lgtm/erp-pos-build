import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { DueThisWeekWidget } from '../DueThisWeekWidget'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/features/treasury/hooks/useMaturingInstruments', () => ({
  useMaturingInstruments: () => ({ data: { meta: { buckets: { overdue: { count: 1, total_in: '10.000', total_out: '0.000' }, d0_7: { count: 2, total_in: '20.000', total_out: '0.000' } } } } }),
}))

describe('DueThisWeekWidget', () => {
  it('renders due count and parameterized instruments link', () => {
    render(<MemoryRouter><DueThisWeekWidget /></MemoryRouter>)
    expect(screen.getByText(/dueThisWeek.summary/)).toBeInTheDocument()
    expect(screen.getByRole('link').getAttribute('href')).toMatch(/maturity_from=/)
  })
})
