import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { TerminalStatusBadge } from './TerminalStatusBadge'

// Mock translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('TerminalStatusBadge', () => {
  it('renders active badge when isActive is true', () => {
    render(<TerminalStatusBadge isActive={true} />)

    const badge = screen.getByText('pos.terminal.active')

    expect(badge).toBeInTheDocument()
    expect(badge).toHaveClass('bg-green-100', 'text-green-800')
  })

  it('renders inactive badge when isActive is false', () => {
    render(<TerminalStatusBadge isActive={false} />)

    const badge = screen.getByText('pos.terminal.inactive')

    expect(badge).toBeInTheDocument()
    expect(badge).toHaveClass('bg-gray-100', 'text-gray-800')
  })

  it('applies custom className when provided', () => {
    const customClass = 'my-custom-class'
    const { container } = render(
      <TerminalStatusBadge isActive={true} className={customClass} />
    )

    const badge = container.firstChild as HTMLElement
    expect(badge).toHaveClass(customClass)
  })

  it('has proper badge structure', () => {
    render(<TerminalStatusBadge isActive={true} />)

    const badge = screen.getByText('pos.terminal.active')

    // Check that it's a span element
    expect(badge.tagName).toBe('SPAN')

    // Check that it has the base badge classes
    expect(badge).toHaveClass('inline-flex', 'items-center', 'rounded-full')
    expect(badge).toHaveClass('px-2.5', 'py-0.5', 'text-xs', 'font-medium')
  })
})
