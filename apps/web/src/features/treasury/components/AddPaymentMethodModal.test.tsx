import { AxiosError, AxiosHeaders } from 'axios'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '../../../test/renderWithProviders'
import { apiPost } from '../../../lib/api'
import { AddPaymentMethodModal } from './AddPaymentMethodModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('../../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../lib/api')>()
  return {
    ...actual,
    apiPost: vi.fn(),
  }
})

describe('AddPaymentMethodModal (canonical modal)', () => {
  const mockApiPost = vi.mocked(apiPost)

  beforeEach(() => {
    mockApiPost.mockReset()
  })

  it('renders the modal title when open', () => {
    renderWithProviders(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    expect(screen.getByText('treasury:paymentMethods.new')).toBeInTheDocument()
  })

  it('renders a primary submit button', () => {
    renderWithProviders(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    const submit = screen.getByRole('button', { name: /common:actions\.save/ })
    expect(submit.tagName).toBe('BUTTON')
    expect(submit).toHaveAttribute('type', 'submit')
  })

  it('renders a cancel button and the form fields', () => {
    renderWithProviders(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    expect(
      screen.getByRole('button', { name: /common:actions\.cancel/ }),
    ).toBeInTheDocument()
    // Code + name inputs render via the FormField/Input atoms.
    expect(document.getElementById('method-code')).toBeInTheDocument()
    expect(document.getElementById('method-name')).toBeInTheDocument()
  })

  it('does not render content when closed', () => {
    renderWithProviders(<AddPaymentMethodModal isOpen={false} onClose={vi.fn()} />)
    expect(screen.queryByText('treasury:paymentMethods.new')).not.toBeInTheDocument()
  })

  it('submits is_cash_tender false by default and true when selected', async () => {
    mockApiPost.mockResolvedValue({
      id: 'method-1',
      code: 'CARD',
      name: 'Card',
      is_physical: false,
      is_cash_tender: false,
      has_maturity: false,
      requires_third_party: false,
      is_push: true,
      has_deducted_fees: false,
      is_restricted: false,
      fee_type: 'none',
      is_active: true,
    })
    const user = userEvent.setup()
    const { unmount } = renderWithProviders(
      <AddPaymentMethodModal isOpen onClose={vi.fn()} />,
    )

    await user.type(screen.getByLabelText(/treasury:paymentMethods\.code/), 'CARD')
    await user.type(screen.getByLabelText(/treasury:paymentMethods\.name/), 'Card')
    await user.click(screen.getByRole('button', { name: /common:actions\.save/ }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith(
        '/payment-methods',
        expect.objectContaining({ is_cash_tender: false }),
      )
    })

    unmount()
    mockApiPost.mockClear()
    renderWithProviders(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)
    await user.type(screen.getByLabelText(/treasury:paymentMethods\.code/), 'CASH')
    await user.type(screen.getByLabelText(/treasury:paymentMethods\.name/), 'Cash')
    await user.click(screen.getByRole('checkbox', {
      name: /^treasury:paymentMethods\.flags\.is_cash_tender/,
    }))
    await user.click(screen.getByRole('button', { name: /common:actions\.save/ }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith(
        '/payment-methods',
        expect.objectContaining({ is_cash_tender: true }),
      )
    })
  })

  it.each([
    [
      'PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE',
      'treasury:paymentMethods.errors.cashTenderFlagOnNonCanonicalCode',
    ],
    [
      'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED',
      'treasury:paymentMethods.errors.canonicalCashCodeNotFlagged',
    ],
  ])('renders the translated %s refusal', async (code, translationKey) => {
    const refusal = new AxiosError('Request failed with status code 422')
    refusal.response = {
      status: 422,
      data: {
        error: { code, message: 'Server fallback' },
        meta: { timestamp: '2026-08-28T00:00:00Z', request_id: 'request-1' },
      },
      statusText: 'Unprocessable Entity',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    mockApiPost.mockRejectedValueOnce(refusal)
    const user = userEvent.setup()
    renderWithProviders(<AddPaymentMethodModal isOpen onClose={vi.fn()} />)

    await user.type(screen.getByLabelText(/treasury:paymentMethods\.code/), 'CASH')
    await user.type(screen.getByLabelText(/treasury:paymentMethods\.name/), 'Cash')
    await user.click(screen.getByRole('button', { name: /common:actions\.save/ }))

    expect(await screen.findByText(translationKey)).toBeInTheDocument()
  })
})
