import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SummaryChips } from '../SummaryChips'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts && 'count' in opts) return `${key}:${String(opts['count'])}`
      return key
    },
  }),
}))

describe('SummaryChips', () => {
  it('renders total chip with correct count', () => {
    render(<SummaryChips total={12} rejectedCount={3} />)
    expect(screen.getByTestId('chip-total')).toHaveTextContent('summary.searches:12')
  })

  it('renders rejected chip with correct count', () => {
    render(<SummaryChips total={12} rejectedCount={3} />)
    expect(screen.getByTestId('chip-rejected')).toHaveTextContent('summary.rejected:3')
  })

  it('renders zero rejected count', () => {
    render(<SummaryChips total={5} rejectedCount={0} />)
    expect(screen.getByTestId('chip-rejected')).toHaveTextContent('summary.rejected:0')
  })

  it('renders total=1 (singular) count', () => {
    render(<SummaryChips total={1} rejectedCount={0} />)
    expect(screen.getByTestId('chip-total')).toHaveTextContent('summary.searches:1')
  })

  it('uses red badge when rejectedCount > 0', () => {
    render(<SummaryChips total={10} rejectedCount={2} />)
    const chip = screen.getByTestId('chip-rejected')
    expect(chip.className).toContain('bg-red')
  })

  it('uses green badge when rejectedCount === 0', () => {
    render(<SummaryChips total={10} rejectedCount={0} />)
    const chip = screen.getByTestId('chip-rejected')
    expect(chip.className).toContain('bg-green')
  })
})
