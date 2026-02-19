import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PaymentStatusBadge } from './PaymentStatusBadge'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: any) => options?.defaultValue || key,
  }),
}))

describe('PaymentStatusBadge', () => {
  it('renders unpaid status with red color', () => {
    render(<PaymentStatusBadge status="unpaid" />)

    const badge = screen.getByText('Unpaid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-red-100')
    expect(badge.className).toContain('text-red-800')
  })

  it('renders partially paid status with yellow color', () => {
    render(<PaymentStatusBadge status="partially_paid" />)

    const badge = screen.getByText('Partially Paid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-yellow-100')
    expect(badge.className).toContain('text-yellow-800')
  })

  it('renders in payment status with blue color', () => {
    render(<PaymentStatusBadge status="in_payment" />)

    const badge = screen.getByText('In Payment')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-blue-100')
    expect(badge.className).toContain('text-blue-800')
  })

  it('renders paid status with green color', () => {
    render(<PaymentStatusBadge status="paid" />)

    const badge = screen.getByText('Paid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-green-100')
    expect(badge.className).toContain('text-green-800')
  })

  it('renders overpaid status with purple color', () => {
    render(<PaymentStatusBadge status="overpaid" />)

    const badge = screen.getByText('Overpaid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-purple-100')
    expect(badge.className).toContain('text-purple-800')
  })

  it('applies custom className', () => {
    render(<PaymentStatusBadge status="paid" className="custom-class" />)

    const badge = screen.getByText('Paid')
    expect(badge.className).toContain('custom-class')
  })
})
