import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SectionNav } from './SectionNav'
import type { EditorSection } from './SectionNav'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

const sections: EditorSection[] = [
  { id: 'identity', label: 'Identity', marker: '01' },
  { id: 'pricing', label: 'Pricing', marker: '02' },
  { id: 'media', label: 'Media', marker: '03' },
]

describe('SectionNav', () => {
  it('renders each section label', () => {
    render(
      <SectionNav
        sections={sections}
        activeId="identity"
        onSelect={vi.fn()}
        completenessPercent={50}
      />,
    )
    expect(screen.getByText('Identity')).toBeInTheDocument()
    expect(screen.getByText('Pricing')).toBeInTheDocument()
    expect(screen.getByText('Media')).toBeInTheDocument()
  })

  it('renders each section marker', () => {
    render(
      <SectionNav
        sections={sections}
        activeId={null}
        onSelect={vi.fn()}
        completenessPercent={0}
      />,
    )
    expect(screen.getByText('01')).toBeInTheDocument()
    expect(screen.getByText('02')).toBeInTheDocument()
    expect(screen.getByText('03')).toBeInTheDocument()
  })

  it('marks the active item with aria-current="step"', () => {
    render(
      <SectionNav
        sections={sections}
        activeId="pricing"
        onSelect={vi.fn()}
        completenessPercent={30}
      />,
    )
    const activeItem = screen.getByRole('button', { name: /pricing/i })
    expect(activeItem).toHaveAttribute('aria-current', 'step')
  })

  it('does not mark inactive items with aria-current', () => {
    render(
      <SectionNav
        sections={sections}
        activeId="pricing"
        onSelect={vi.fn()}
        completenessPercent={30}
      />,
    )
    const identityButton = screen.getByRole('button', { name: /identity/i })
    expect(identityButton).not.toHaveAttribute('aria-current')
  })

  it('calls onSelect with the section id when an item is clicked', async () => {
    const onSelect = vi.fn()
    const user = userEvent.setup()
    render(
      <SectionNav
        sections={sections}
        activeId="identity"
        onSelect={onSelect}
        completenessPercent={75}
      />,
    )
    await user.click(screen.getByRole('button', { name: /media/i }))
    expect(onSelect).toHaveBeenCalledWith('media')
  })

  it('renders the completeness meter', () => {
    const { container } = render(
      <SectionNav
        sections={sections}
        activeId={null}
        onSelect={vi.fn()}
        completenessPercent={60}
      />,
    )
    const fill = container.querySelector('[data-testid="completeness-fill"]')
    expect(fill).not.toBeNull()
    expect((fill as HTMLElement).style.width).toBe('60%')
  })

  it('renders the SECTIONS heading', () => {
    render(
      <SectionNav
        sections={sections}
        activeId={null}
        onSelect={vi.fn()}
        completenessPercent={0}
      />,
    )
    // catalog.editor.sections resolves to "SECTIONS" in EN
    expect(screen.getByText(/sections/i)).toBeInTheDocument()
  })
})
