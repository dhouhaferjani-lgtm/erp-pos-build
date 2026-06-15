import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QuickAddCustomerModal } from './QuickAddCustomerModal'

// Mock translation hook — return the key (plus interpolated params) verbatim.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

// Isolate from the network — the modal only needs apiPost to exist.
vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}))

describe('QuickAddCustomerModal (color-drift)', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    onCustomerCreated: vi.fn(),
  }

  it('renders nothing when closed', () => {
    const { container } = render(
      <QuickAddCustomerModal {...defaultProps} isOpen={false} />
    )
    expect(container).toBeEmptyDOMElement()
  })

  it('renders the heading and form fields when open', () => {
    render(<QuickAddCustomerModal {...defaultProps} />)

    expect(screen.getByText('pos:cart.quickAddCustomer')).toBeInTheDocument()
    expect(screen.getByLabelText(/pos:cart.customerName/)).toBeInTheDocument()
    expect(screen.getByLabelText(/pos:cart.customerPhone/)).toBeInTheDocument()
    expect(screen.getByLabelText(/pos:cart.customerEmail/)).toBeInTheDocument()
  })

  it('renders cancel and create actions', () => {
    render(<QuickAddCustomerModal {...defaultProps} />)

    expect(
      screen.getByRole('button', { name: 'common:actions.cancel' })
    ).toBeInTheDocument()
    // Submit button disabled until a name is entered.
    expect(
      screen.getByRole('button', { name: 'common:actions.create' })
    ).toBeDisabled()
  })
})
