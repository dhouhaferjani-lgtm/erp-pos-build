import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { EditorSectionCard } from './EditorSectionCard'

describe('EditorSectionCard', () => {
  it('renders the marker, the title as an h2, and the children', () => {
    render(
      <EditorSectionCard id="section-general" marker="01" title="General">
        <p>field content</p>
      </EditorSectionCard>,
    )
    const heading = screen.getByRole('heading', { level: 2, name: /general/i })
    expect(heading).toBeInTheDocument()
    expect(screen.getByText('01')).toBeInTheDocument()
    expect(screen.getByText('field content')).toBeInTheDocument()
  })

  it('exposes its id on the outer element for scroll-spy anchoring', () => {
    const { container } = render(
      <EditorSectionCard id="section-pricing" marker="02" title="Pricing">
        <span>x</span>
      </EditorSectionCard>,
    )
    const section = container.querySelector('#section-pricing')
    expect(section).not.toBeNull()
  })

  it('uses the flat hairline card token (no heavy shadow)', () => {
    const { container } = render(
      <EditorSectionCard id="section-x" marker="03" title="X">
        <span>x</span>
      </EditorSectionCard>,
    )
    const section = container.querySelector('#section-x') as HTMLElement
    expect(section.className).toContain('border')
  })
})
