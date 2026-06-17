import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { WithholdingPreviewModal } from './WithholdingPreviewModal'

const mockPreviewMutation = vi.hoisted(() => ({
  mutate: vi.fn(),
  data: undefined as unknown,
  isPending: false,
  isError: false,
  error: null as unknown,
}))

vi.mock('../hooks/useWithholding', () => ({
  useWithholdingPreview: () => mockPreviewMutation,
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
  getDecimals: () => 3,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: unknown) =>
      typeof options === 'string' ? options : key,
  }),
}))

describe('WithholdingPreviewModal', () => {
  const baseProps = {
    isOpen: true,
    onClose: vi.fn(),
    onApply: vi.fn(),
    partnerId: 'partner-1',
    amount: '1000.000',
    currency: 'TND',
  }

  it('renders the modal title when open', () => {
    mockPreviewMutation.data = undefined
    render(<WithholdingPreviewModal {...baseProps} />)
    expect(screen.getByText('preview.title')).toBeInTheDocument()
  })

  it('renders the apply action as a button (atom)', () => {
    mockPreviewMutation.data = undefined
    render(<WithholdingPreviewModal {...baseProps} />)
    const applyButton = screen.getByRole('button', { name: 'preview.applyWithholding' })
    expect(applyButton.tagName).toBe('BUTTON')
  })

  it('shows the previewed figures from the calculation', () => {
    mockPreviewMutation.data = {
      should_withhold: true,
      withholding_amount: '100.000',
      withholding_rate: '0.1000',
      net_amount: '900.000',
      rate_percentage: 10,
      rule: { id: 'r1', code: 'WHT-10', name: 'Services 10%' },
    }
    render(<WithholdingPreviewModal {...baseProps} />)

    // Net amount figure rendered
    expect(screen.getByText(/900\.000/)).toBeInTheDocument()
    // Withholding amount figure rendered
    expect(screen.getByText(/100\.000/)).toBeInTheDocument()
    // Rule details surfaced in the recommendation panel
    expect(screen.getByText(/Services 10%/)).toBeInTheDocument()
  })
})
