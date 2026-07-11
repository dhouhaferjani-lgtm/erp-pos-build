import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { POSButton } from './POSButton'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

describe('POSButton', () => {
  it('renders children correctly', () => {
    const { getByText } = render(<POSButton>Click Me</POSButton>)
    expect(getByText('Click Me')).toBeInTheDocument()
  })

  it('applies primary variant classes by default', () => {
    const { container } = render(<POSButton>Primary</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain(colorTokens.intent.primary.bgStrong)
    expect(button.className).toContain(colorTokens.text.inverse)
  })

  it('applies secondary variant classes when specified', () => {
    const { container } = render(<POSButton variant="secondary">Secondary</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain(colorTokens.surface.subdued)
    expect(button.className).toContain(colorTokens.text.primary)
  })

  it('applies success variant classes when specified', () => {
    const { container } = render(<POSButton variant="success">Success</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain(colorTokens.intent.success.bgStrong)
    expect(button.className).toContain(colorTokens.text.inverse)
  })

  it('applies danger variant classes when specified', () => {
    const { container } = render(<POSButton variant="danger">Danger</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain(colorTokens.intent.danger.bgStrong)
    expect(button.className).toContain(colorTokens.text.inverse)
  })

  it('applies medium size classes by default', () => {
    const { container } = render(<POSButton>Medium</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('px-4')
    expect(button.className).toContain('py-2')
    expect(button.className).toContain('text-base')
  })

  it('applies small size classes when specified', () => {
    const { container } = render(<POSButton size="sm">Small</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('px-3')
    expect(button.className).toContain('py-1.5')
    expect(button.className).toContain('text-sm')
  })

  it('applies large size classes when specified', () => {
    const { container } = render(<POSButton size="lg">Large</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('px-6')
    expect(button.className).toContain('py-3')
    expect(button.className).toContain('text-lg')
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(<POSButton touchOptimized>Touch</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('min-h-[48px]')
    expect(button.className).toContain('min-w-[48px]')
  })

  it('applies full width when fullWidth is true', () => {
    const { container } = render(<POSButton fullWidth>Full Width</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('w-full')
  })

  it('calls onClick when clicked', () => {
    const onClick = vi.fn()
    const { getByText } = render(<POSButton onClick={onClick}>Click</POSButton>)
    fireEvent.click(getByText('Click'))
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('does not call onClick when disabled', () => {
    const onClick = vi.fn()
    const { getByText } = render(
      <POSButton onClick={onClick} disabled>
        Disabled
      </POSButton>
    )
    fireEvent.click(getByText('Disabled'))
    expect(onClick).not.toHaveBeenCalled()
  })

  it('applies disabled styles when disabled', () => {
    const { container } = render(<POSButton disabled>Disabled</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('opacity-50')
    expect(button.className).toContain('cursor-not-allowed')
    expect(button).toBeDisabled()
  })

  it('renders with icon when provided', () => {
    const Icon = () => <svg data-testid="test-icon" />
    const { getByTestId } = render(
      <POSButton icon={<Icon />}>With Icon</POSButton>
    )
    expect(getByTestId('test-icon')).toBeInTheDocument()
  })

  it('renders as icon-only button when no children provided', () => {
    const Icon = () => <svg data-testid="test-icon" />
    const { getByTestId, container } = render(<POSButton icon={<Icon />} />)
    const button = container.firstChild as HTMLElement
    expect(getByTestId('test-icon')).toBeInTheDocument()
    expect(button.className).toContain('p-2')
  })

  it('applies custom className', () => {
    const { container } = render(<POSButton className="custom-class">Custom</POSButton>)
    const button = container.firstChild as HTMLElement
    expect(button.className).toContain('custom-class')
  })

  it('forwards ref correctly', () => {
    const ref = vi.fn()
    render(<POSButton ref={ref}>Ref Test</POSButton>)
    expect(ref).toHaveBeenCalled()
  })
})
