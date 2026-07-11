import { render, screen } from '@testing-library/react'
import { TrendingUp } from 'lucide-react'
import { describe, expect, it, vi } from 'vitest'
import { StatCard } from './StatCard'

const tokenClasses = vi.hoisted(() => ({
  borderColors: {
    light: 'token-border-light',
  },
  colors: {
    neutral: {
      100: 'token-surface-muted',
    },
    white: 'token-surface-base',
  },
  textColors: {
    error: 'token-text-error',
    primary: 'token-text-primary',
    success: 'token-text-success',
    tertiary: 'token-text-tertiary',
  },
}))

vi.mock('../../lib/designTokens', () => tokenClasses)

describe('StatCard design tokens', () => {
  it('uses design token classes for card, text, trend, and icon colors', () => {
    const { container } = render(
      <StatCard
        label="Revenue"
        value="1 000,000 TND"
        icon={TrendingUp}
        trend={{ value: 12, label: 'month over month', isPositive: true }}
      />
    )

    expect(container.firstChild).toHaveClass('token-surface-base')
    expect(container.firstChild).toHaveClass('token-border-light')
    expect(screen.getByText('Revenue')).toHaveClass('token-text-tertiary')
    expect(screen.getByText('1 000,000 TND')).toHaveClass('token-text-primary')
    expect(screen.getByText('+12%')).toHaveClass('token-text-success')
    expect(container.querySelector('.token-surface-muted svg')).toHaveClass(
      'token-text-tertiary'
    )
  })
})
