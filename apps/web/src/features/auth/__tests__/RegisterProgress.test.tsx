import { render, screen } from '@testing-library/react'
import { describe, it, expect } from 'vitest'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { RegisterProgress } from '../components/RegisterProgress'

describe('RegisterProgress', () => {
  it('renders with correct aria attributes', () => {
    render(<RegisterProgress currentStep={2} totalSteps={4} />)

    const progressbar = screen.getByRole('progressbar')
    expect(progressbar).toHaveAttribute('aria-valuenow', '2')
    expect(progressbar).toHaveAttribute('aria-valuemin', '1')
    expect(progressbar).toHaveAttribute('aria-valuemax', '4')
  })

  it('renders 4 segments', () => {
    render(<RegisterProgress currentStep={1} totalSteps={4} />)

    const progressbar = screen.getByRole('progressbar')
    const segments = progressbar.querySelectorAll('.rounded-full')
    expect(segments).toHaveLength(4)
  })

  it('fills correct number of segments based on currentStep', () => {
    const { rerender } = render(<RegisterProgress currentStep={1} totalSteps={4} />)

    let progressbar = screen.getByRole('progressbar')
    let segments = Array.from(progressbar.querySelectorAll('.rounded-full'))
    let filled = segments.filter((segment) => segment.className.includes(colorTokens.intent.primary.bg))
    let unfilled = segments.filter((segment) => segment.className.includes(colorTokens.surface.subdued))
    expect(filled).toHaveLength(1)
    expect(unfilled).toHaveLength(3)

    rerender(<RegisterProgress currentStep={3} totalSteps={4} />)

    progressbar = screen.getByRole('progressbar')
    segments = Array.from(progressbar.querySelectorAll('.rounded-full'))
    filled = segments.filter((segment) => segment.className.includes(colorTokens.intent.primary.bg))
    unfilled = segments.filter((segment) => segment.className.includes(colorTokens.surface.subdued))
    expect(filled).toHaveLength(3)
    expect(unfilled).toHaveLength(1)

    rerender(<RegisterProgress currentStep={4} totalSteps={4} />)

    progressbar = screen.getByRole('progressbar')
    segments = Array.from(progressbar.querySelectorAll('.rounded-full'))
    filled = segments.filter((segment) => segment.className.includes(colorTokens.intent.primary.bg))
    unfilled = segments.filter((segment) => segment.className.includes(colorTokens.surface.subdued))
    expect(filled).toHaveLength(4)
    expect(unfilled).toHaveLength(0)
  })
})
