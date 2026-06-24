import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { EditorSectionCard } from './EditorSectionCard'

describe('EditorSectionCard', () => {
  it('renders the title as an h2 and the children', () => {
    render(
      <EditorSectionCard id="section-general" title="General">
        <p>field content</p>
      </EditorSectionCard>,
    )
    const heading = screen.getByRole('heading', { level: 2, name: /general/i })
    expect(heading).toBeInTheDocument()
    expect(screen.getByText('field content')).toBeInTheDocument()
  })

  it('does NOT render a numeric ordinal marker (mock has none)', () => {
    render(
      <EditorSectionCard id="section-general" title="General">
        <p>field content</p>
      </EditorSectionCard>,
    )
    expect(screen.queryByText('01')).toBeNull()
    expect(screen.queryByText('02')).toBeNull()
  })

  it('exposes its id on the outer element for scroll-spy anchoring', () => {
    const { container } = render(
      <EditorSectionCard id="section-pricing" title="Pricing">
        <span>x</span>
      </EditorSectionCard>,
    )
    const section = container.querySelector('#section-pricing')
    expect(section).not.toBeNull()
  })

  it('uses the flat hairline card token (no heavy shadow)', () => {
    const { container } = render(
      <EditorSectionCard id="section-x" title="X">
        <span>x</span>
      </EditorSectionCard>,
    )
    const section = container.querySelector('#section-x') as HTMLElement
    expect(section.className).toContain('border')
  })

  it('renders the header in the display font (Montserrat via --font-display)', () => {
    render(
      <EditorSectionCard id="section-h" title="Pricing & Tax">
        <span>x</span>
      </EditorSectionCard>,
    )
    const heading = screen.getByRole('heading', { level: 2, name: /pricing/i })
    expect(heading.className).toContain('font-[family-name:var(--font-display)]')
  })
})
