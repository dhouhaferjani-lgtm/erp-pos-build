import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { AddPaymentMethodModal } from './AddPaymentMethodModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('../../../lib/api', () => ({
  apiPost: vi.fn(),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
    useMutation: () => ({ mutate: vi.fn(), isPending: false, isError: false, error: null }),
  }
})

describe('AddPaymentMethodModal (canonical modal)', () => {
  it('renders the modal title when open', () => {
    render(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    expect(screen.getByText('treasury:paymentMethods.new')).toBeInTheDocument()
  })

  it('renders a primary submit button', () => {
    render(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    const submit = screen.getByRole('button', { name: /common:actions\.save/ })
    expect(submit.tagName).toBe('BUTTON')
    expect(submit).toHaveAttribute('type', 'submit')
  })

  it('renders a cancel button and the form fields', () => {
    render(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    expect(
      screen.getByRole('button', { name: /common:actions\.cancel/ }),
    ).toBeInTheDocument()
    // Code + name inputs render via the FormField/Input atoms.
    expect(document.getElementById('method-code')).toBeInTheDocument()
    expect(document.getElementById('method-name')).toBeInTheDocument()
  })

  it('does not render content when closed', () => {
    render(<AddPaymentMethodModal isOpen={false} onClose={vi.fn()} />)
    expect(screen.queryByText('treasury:paymentMethods.new')).not.toBeInTheDocument()
  })
})
