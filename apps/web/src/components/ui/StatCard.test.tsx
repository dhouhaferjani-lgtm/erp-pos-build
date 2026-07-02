import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StatCard } from './StatCard'
import { TrendingUp } from 'lucide-react'
import { colors, textColors } from '../../lib/designTokens'

describe('StatCard', () => {
  it('renders label and value', () => {
    render(<StatCard label="Total Products" value={247} />)
    expect(screen.getByText('Total Products')).toBeInTheDocument()
    expect(screen.getByText('247')).toBeInTheDocument()
  })

  it('renders string value', () => {
    render(<StatCard label="Revenue" value="$45,892.50" />)
    expect(screen.getByText('Revenue')).toBeInTheDocument()
    expect(screen.getByText('$45,892.50')).toBeInTheDocument()
  })

  it('renders number value', () => {
    render(<StatCard label="Count" value={12345} />)
    expect(screen.getByText('Count')).toBeInTheDocument()
    expect(screen.getByText('12345')).toBeInTheDocument()
  })

  it('renders zero value', () => {
    render(<StatCard label="Items" value={0} />)
    expect(screen.getByText('Items')).toBeInTheDocument()
    expect(screen.getByText('0')).toBeInTheDocument()
  })

  it('does not render icon when not provided', () => {
    const { container } = render(<StatCard label="Total" value={100} />)
    // Icon container should not exist
    const iconContainer = container.querySelector('.w-12.h-12')
    expect(iconContainer).not.toBeInTheDocument()
  })

  it('renders icon when provided', () => {
    const { container } = render(<StatCard label="Total" value={100} icon={TrendingUp} />)
    const iconContainer = container.querySelector('.w-12.h-12')
    expect(iconContainer).toBeInTheDocument()
    expect(iconContainer?.querySelector('svg')).toBeInTheDocument()
  })

  it('does not render trend when not provided', () => {
    render(<StatCard label="Total" value={100} />)
    expect(screen.queryByText(/%/)).not.toBeInTheDocument()
  })

  it('renders positive trend with correct styling', () => {
    render(
      <StatCard
        label="Sales"
        value={1000}
        trend={{ value: 12.5, label: 'from last month', isPositive: true }}
      />
    )
    expect(screen.getByText('+12.5%')).toBeInTheDocument()
    expect(screen.getByText('from last month')).toBeInTheDocument()
    expect(screen.getByText('+12.5%')).toHaveClass(textColors.success)
  })

  it('renders negative trend with correct styling', () => {
    render(
      <StatCard
        label="Sales"
        value={1000}
        trend={{ value: -5.2, label: 'from last month', isPositive: false }}
      />
    )
    expect(screen.getByText('-5.2%')).toBeInTheDocument()
    expect(screen.getByText('from last month')).toBeInTheDocument()
    expect(screen.getByText('-5.2%')).toHaveClass(textColors.error)
  })

  it('renders trend without plus sign when isPositive is false', () => {
    render(
      <StatCard
        label="Sales"
        value={1000}
        trend={{ value: 5, label: 'from last month', isPositive: false }}
      />
    )
    expect(screen.getByText('5%')).toBeInTheDocument()
  })

  it('renders trend with plus sign when isPositive is true', () => {
    render(
      <StatCard
        label="Sales"
        value={1000}
        trend={{ value: 5, label: 'from last month', isPositive: true }}
      />
    )
    expect(screen.getByText('+5%')).toBeInTheDocument()
  })

  it('defaults trend to negative styling when isPositive is undefined', () => {
    render(
      <StatCard
        label="Sales"
        value={1000}
        trend={{ value: 5, label: 'from last month' }}
      />
    )
    const trendValue = screen.getByText('5%')
    expect(trendValue).toHaveClass(textColors.error)
  })

  it('applies custom className', () => {
    const { container } = render(
      <StatCard label="Total" value={100} className="custom-class" />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('maintains base classes with custom className', () => {
    const { container } = render(
      <StatCard label="Total" value={100} className="custom-class" />
    )
    expect(container.firstChild).toHaveClass('bg-white')
    expect(container.firstChild).toHaveClass('rounded-lg')
    expect(container.firstChild).toHaveClass('border')
    expect(container.firstChild).toHaveClass('p-6')
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('renders complete card with all props', () => {
    render(
      <StatCard
        label="Total Revenue"
        value="$125,430"
        icon={TrendingUp}
        trend={{ value: 15.3, label: 'vs last month', isPositive: true }}
        className="custom-stat"
      />
    )
    expect(screen.getByText('Total Revenue')).toBeInTheDocument()
    expect(screen.getByText('$125,430')).toBeInTheDocument()
    expect(screen.getByText('+15.3%')).toBeInTheDocument()
    expect(screen.getByText('vs last month')).toBeInTheDocument()
  })

  it('renders label with correct styling', () => {
    render(<StatCard label="Test Label" value={100} />)
    const label = screen.getByText('Test Label')
    expect(label).toHaveClass('text-sm')
    expect(label).toHaveClass('font-medium')
    expect(label).toHaveClass(textColors.tertiary)
  })

  it('renders value with correct styling', () => {
    render(<StatCard label="Test" value={100} />)
    const value = screen.getByText('100')
    expect(value).toHaveClass('text-3xl')
    expect(value).toHaveClass('font-semibold')
    expect(value).toHaveClass(textColors.primary)
  })

  it('renders trend label with correct styling', () => {
    render(
      <StatCard
        label="Test"
        value={100}
        trend={{ value: 5, label: 'trend label', isPositive: true }}
      />
    )
    const trendLabel = screen.getByText('trend label')
    const trendContainer = trendLabel.closest('p')
    expect(trendContainer).toHaveClass('text-sm')
    expect(trendContainer).toHaveClass(textColors.tertiary)
  })

  it('handles decimal trend values', () => {
    render(
      <StatCard
        label="Test"
        value={100}
        trend={{ value: 12.345, label: 'change', isPositive: true }}
      />
    )
    expect(screen.getByText('+12.345%')).toBeInTheDocument()
  })

  it('handles negative decimal trend values', () => {
    render(
      <StatCard
        label="Test"
        value={100}
        trend={{ value: -8.75, label: 'change', isPositive: false }}
      />
    )
    expect(screen.getByText('-8.75%')).toBeInTheDocument()
  })

  it('icon container has correct background', () => {
    const { container } = render(<StatCard label="Test" value={100} icon={TrendingUp} />)
    const iconContainer = container.querySelector('.w-12.h-12')
    expect(iconContainer).toHaveClass(colors.neutral[100])
    expect(iconContainer).toHaveClass('rounded-lg')
  })

  it('icon has correct size and color', () => {
    const { container } = render(<StatCard label="Test" value={100} icon={TrendingUp} />)
    const icon = container.querySelector('.w-6.h-6')
    expect(icon).toBeInTheDocument()
    expect(icon).toHaveClass(textColors.tertiary)
  })
})
