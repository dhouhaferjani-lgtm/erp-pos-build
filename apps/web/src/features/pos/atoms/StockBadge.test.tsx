import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { StockBadge } from './StockBadge'

describe('StockBadge', () => {
  it('renders "In Stock" when quantity is greater than threshold', () => {
    const { getByText } = render(<StockBadge quantity={100} threshold={10} />)
    expect(getByText('In Stock')).toBeInTheDocument()
  })

  it('renders "Low Stock" when quantity is at or below threshold', () => {
    const { getByText } = render(<StockBadge quantity={10} threshold={10} />)
    expect(getByText('Low Stock')).toBeInTheDocument()
  })

  it('renders "Out of Stock" when quantity is zero', () => {
    const { getByText } = render(<StockBadge quantity={0} threshold={10} />)
    expect(getByText('Out of Stock')).toBeInTheDocument()
  })

  it('uses semantic labels for each stock state', () => {
    render(
      <>
        <StockBadge quantity={100} threshold={10} />
        <StockBadge quantity={5} threshold={10} />
        <StockBadge quantity={0} threshold={10} />
      </>
    )

    expect(screen.getByText('In Stock')).toBeInTheDocument()
    expect(screen.getByText('Low Stock')).toBeInTheDocument()
    expect(screen.getByText('Out of Stock')).toBeInTheDocument()
  })

  it('displays quantity when showQuantity is true', () => {
    const { getByText } = render(
      <StockBadge quantity={50} threshold={10} showQuantity />
    )
    expect(getByText(/50/)).toBeInTheDocument()
  })

  it('does not display quantity when showQuantity is false', () => {
    const { queryByText } = render(
      <StockBadge quantity={50} threshold={10} showQuantity={false} />
    )
    expect(queryByText(/50/)).not.toBeInTheDocument()
  })

  it('uses default threshold of 10 when not provided', () => {
    const { getByText } = render(<StockBadge quantity={5} />)
    expect(getByText('Low Stock')).toBeInTheDocument()
  })

  it('handles negative quantities as out of stock', () => {
    const { getByText } = render(<StockBadge quantity={-5} threshold={10} />)
    expect(getByText('Out of Stock')).toBeInTheDocument()
  })

  it('applies small size classes by default', () => {
    const { container } = render(<StockBadge quantity={50} threshold={10} />)
    const badge = container.firstChild as HTMLElement
    expect(badge.className).toContain('text-xs')
    expect(badge.className).toContain('px-2')
    expect(badge.className).toContain('py-1')
  })

  it('applies medium size classes when specified', () => {
    const { container } = render(
      <StockBadge quantity={50} threshold={10} size="md" />
    )
    const badge = container.firstChild as HTMLElement
    expect(badge.className).toContain('text-sm')
    expect(badge.className).toContain('px-3')
    expect(badge.className).toContain('py-1.5')
  })

  it('applies large size classes when specified', () => {
    const { container } = render(
      <StockBadge quantity={50} threshold={10} size="lg" />
    )
    const badge = container.firstChild as HTMLElement
    expect(badge.className).toContain('text-base')
    expect(badge.className).toContain('px-4')
    expect(badge.className).toContain('py-2')
  })

  it('displays custom unit when provided', () => {
    const { getByText } = render(
      <StockBadge quantity={50} threshold={10} showQuantity unit="pcs" />
    )
    expect(getByText(/pcs/)).toBeInTheDocument()
  })

  it('uses default unit when not provided', () => {
    const { getByText } = render(
      <StockBadge quantity={50} threshold={10} showQuantity />
    )
    expect(getByText(/units/)).toBeInTheDocument()
  })

  it('applies custom className', () => {
    const { container } = render(
      <StockBadge quantity={50} threshold={10} className="custom-class" />
    )
    const badge = container.firstChild as HTMLElement
    expect(badge.className).toContain('custom-class')
  })

  it('handles decimal quantities correctly', () => {
    const { getByText } = render(
      <StockBadge quantity={15.5} threshold={10} showQuantity />
    )
    expect(getByText(/15\.5/)).toBeInTheDocument()
  })

  it('shows correct status for quantity equal to threshold', () => {
    const { getByText } = render(<StockBadge quantity={10} threshold={10} />)
    expect(getByText('Low Stock')).toBeInTheDocument()
  })

  it('shows correct status for quantity just above threshold', () => {
    const { getByText } = render(<StockBadge quantity={11} threshold={10} />)
    expect(getByText('In Stock')).toBeInTheDocument()
  })

  it('displays indicator dot', () => {
    const { container } = render(<StockBadge quantity={50} threshold={10} />)
    const badge = container.firstChild as HTMLElement
    const dot = badge.querySelector('span')
    expect(dot).toBeInTheDocument()
    expect(dot?.className).toContain('rounded-full')
  })

})
