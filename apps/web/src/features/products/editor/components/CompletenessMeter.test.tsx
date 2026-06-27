import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CompletenessMeter } from './CompletenessMeter'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

describe('CompletenessMeter', () => {
  it('renders the percent value in the label', () => {
    render(<CompletenessMeter percent={42} />)
    expect(screen.getByText(/42%/)).toBeInTheDocument()
  })

  it('sets the fill element width to the given percent', () => {
    const { container } = render(<CompletenessMeter percent={65} />)
    const fill = container.querySelector('[data-testid="completeness-fill"]')
    expect(fill).not.toBeNull()
    expect((fill as HTMLElement).style.width).toBe('65%')
  })

  it('clamps values below 0 to 0%', () => {
    const { container } = render(<CompletenessMeter percent={-10} />)
    const fill = container.querySelector('[data-testid="completeness-fill"]')
    expect((fill as HTMLElement).style.width).toBe('0%')
    expect(screen.getByText(/0%/)).toBeInTheDocument()
  })

  it('clamps values above 100 to 100%', () => {
    const { container } = render(<CompletenessMeter percent={150} />)
    const fill = container.querySelector('[data-testid="completeness-fill"]')
    expect((fill as HTMLElement).style.width).toBe('100%')
    expect(screen.getByText(/100%/)).toBeInTheDocument()
  })

  it('renders the "complete" label text', () => {
    render(<CompletenessMeter percent={80} />)
    // The translation key catalog.editor.complete resolves to "complete" in EN
    expect(screen.getByText(/complete/i)).toBeInTheDocument()
  })
})
