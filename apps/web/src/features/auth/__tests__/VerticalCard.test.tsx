import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { VerticalCard } from '../components/VerticalCard'
import type { VerticalConfig } from '../config/verticals'
import { Store } from 'lucide-react'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

const mockVertical: VerticalConfig = {
  key: 'retail',
  icon: Store,
  bgColor: 'bg-sky-50',
  strokeColor: 'text-sky-600',
  labelKey: 'auth:verticals.retail.label',
  descriptionKey: 'auth:verticals.retail.description',
}

describe('VerticalCard', () => {
  it('renders vertical name and description', () => {
    render(
      <VerticalCard vertical={mockVertical} selected={false} onSelect={vi.fn()} />
    )

    expect(screen.getByText('auth:verticals.retail.label')).toBeInTheDocument()
    expect(screen.getByText('auth:verticals.retail.description')).toBeInTheDocument()
  })

  it('calls onSelect when clicked', () => {
    const onSelect = vi.fn()
    render(
      <VerticalCard vertical={mockVertical} selected={false} onSelect={onSelect} />
    )

    fireEvent.click(screen.getByRole('option'))
    expect(onSelect).toHaveBeenCalledOnce()
  })

  it('has aria-selected="true" when selected', () => {
    render(
      <VerticalCard vertical={mockVertical} selected={true} onSelect={vi.fn()} />
    )

    expect(screen.getByRole('option')).toHaveAttribute('aria-selected', 'true')
  })

  it('has aria-selected="false" when not selected', () => {
    render(
      <VerticalCard vertical={mockVertical} selected={false} onSelect={vi.fn()} />
    )

    expect(screen.getByRole('option')).toHaveAttribute('aria-selected', 'false')
  })

  it('applies selected styling classes when selected', () => {
    const { rerender } = render(
      <VerticalCard vertical={mockVertical} selected={true} onSelect={vi.fn()} />
    )

    const button = screen.getByRole('option')
    expect(button.className).toContain('border-blue-500')
    expect(button.className).toContain('bg-blue-50')

    rerender(
      <VerticalCard vertical={mockVertical} selected={false} onSelect={vi.fn()} />
    )

    expect(button.className).toContain('border-gray-200')
    expect(button.className).not.toContain('border-blue-500')
  })
})
