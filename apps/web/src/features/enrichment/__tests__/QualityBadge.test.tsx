import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QualityBadge } from '../components/QualityBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) =>
      ({ 'quality.high': 'High', 'quality.medium': 'Medium', 'quality.low': 'Low' }[key] ?? key),
  }),
}))

describe('QualityBadge', () => {
  it('renders high with green badge style', () => {
    render(<QualityBadge quality="high" />)
    expect(screen.getByText('High').className).toContain('bg-green')
  })

  it('renders medium with yellow badge style', () => {
    render(<QualityBadge quality="medium" />)
    expect(screen.getByText('Medium').className).toContain('bg-yellow')
  })

  it('renders low with red badge style', () => {
    render(<QualityBadge quality="low" />)
    expect(screen.getByText('Low').className).toContain('bg-red')
  })
})
