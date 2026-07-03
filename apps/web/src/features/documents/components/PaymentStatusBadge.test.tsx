import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PaymentStatusBadge } from './PaymentStatusBadge'
import { tokens } from '../../../lib/designTokens'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: any) => options?.defaultValue || key,
  }),
}))

describe('PaymentStatusBadge', () => {
  it('renders unpaid status with the danger tone', () => {
    render(<PaymentStatusBadge status="unpaid" />)

    const badge = screen.getByText('Unpaid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(tokens.alert.error)
  })

  it('renders partially paid status with the warning tone', () => {
    render(<PaymentStatusBadge status="partially_paid" />)

    const badge = screen.getByText('Partially Paid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(tokens.alert.warning)
  })

  it('renders in payment status with the info tone', () => {
    render(<PaymentStatusBadge status="in_payment" />)

    const badge = screen.getByText('In Payment')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(tokens.alert.info)
  })

  it('renders paid status with the success tone', () => {
    render(<PaymentStatusBadge status="paid" />)

    const badge = screen.getByText('Paid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(tokens.alert.success)
  })

  it('renders overpaid status with the warning tone', () => {
    render(<PaymentStatusBadge status="overpaid" />)

    const badge = screen.getByText('Overpaid')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(tokens.alert.warning)
  })

  it('renders at the shared badge size', () => {
    render(<PaymentStatusBadge status="paid" />)

    const badge = screen.getByText('Paid')
    expect(badge.className).toContain('text-xs')
  })

  it('applies custom className', () => {
    render(<PaymentStatusBadge status="paid" className="custom-class" />)

    const badge = screen.getByText('Paid')
    expect(badge.className).toContain('custom-class')
  })
})
