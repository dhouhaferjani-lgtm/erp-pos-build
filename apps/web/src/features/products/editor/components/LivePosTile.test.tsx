import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { LivePosTile } from './LivePosTile'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

describe('LivePosTile', () => {
  it('renders a "Live on POS" label', () => {
    render(<LivePosTile name="Paracetamol 500mg ×24" price="$2.85" />)
    // catalog.editor.livePos.title resolves to "Live on POS" in EN
    expect(screen.getByText(/live on pos/i)).toBeInTheDocument()
  })

  it('renders the product name passed in', () => {
    render(<LivePosTile name="Paracetamol 500mg ×24" price="$2.85" />)
    expect(screen.getByText('Paracetamol 500mg ×24')).toBeInTheDocument()
  })

  it('renders the formatted price passed in', () => {
    render(<LivePosTile name="Paracetamol 500mg ×24" price="$2.85" />)
    expect(screen.getByText('$2.85')).toBeInTheDocument()
  })

  it('falls back to a placeholder name when name is empty', () => {
    render(<LivePosTile name="" price="$0.00" />)
    // catalog.editor.livePos.namePlaceholder resolves to "Product name" in EN
    expect(screen.getByText(/product name/i)).toBeInTheDocument()
  })

  it('renders the price in the mono font for numeric legibility', () => {
    const { container } = render(<LivePosTile name="X" price="$2.85" />)
    const price = screen.getByText('$2.85')
    expect(price.className).toContain('font-mono')
    // the price is the orange accent (theme-bridged secondary-500, #EA661A per mock)
    expect(price.className).toContain('text-secondary-500')
    expect(container.firstChild).not.toBeNull()
  })
})
