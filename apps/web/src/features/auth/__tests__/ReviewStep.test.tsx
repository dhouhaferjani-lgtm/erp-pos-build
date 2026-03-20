import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { ReviewStep } from '../components/ReviewStep'
import type { RegisterFormData } from '../hooks/useRegisterForm'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

vi.mock('@/contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({
    product: 'izipos' as const,
    productName: 'IziPOS',
    isIziPOS: true,
    isOtospex: false,
    productDescription: 'Test',
  }),
}))

const baseFormData: RegisterFormData = {
  name: 'John Doe',
  email: 'john@example.com',
  password: 'strongpassword123',
  countryCode: 'FR',
  vertical: 'retail',
  companyName: 'Test Corp',
  phoneLocal: '612345678',
  acceptedTerms: false,
}

describe('ReviewStep', () => {
  const defaultProps = {
    formData: baseFormData,
    errors: {} as Record<string, string | undefined>,
    updateField: vi.fn(),
    onGoToStep: vi.fn(),
    onSubmit: vi.fn(),
    isSubmitting: false,
  }

  it('renders summary data (name, email, company)', () => {
    render(<ReviewStep {...defaultProps} />)

    expect(screen.getByText('John Doe')).toBeInTheDocument()
    expect(screen.getByText('john@example.com')).toBeInTheDocument()
    expect(screen.getByText('Test Corp')).toBeInTheDocument()
  })

  it('edit buttons call onGoToStep with correct step number', () => {
    const onGoToStep = vi.fn()
    render(<ReviewStep {...defaultProps} onGoToStep={onGoToStep} />)

    const editButtons = screen.getAllByLabelText('auth:register.editSection')
    expect(editButtons).toHaveLength(3)

    fireEvent.click(editButtons[0]!) // Account section
    expect(onGoToStep).toHaveBeenCalledWith(1)

    fireEvent.click(editButtons[1]!) // Business section
    expect(onGoToStep).toHaveBeenCalledWith(2)

    fireEvent.click(editButtons[2]!) // Company section
    expect(onGoToStep).toHaveBeenCalledWith(3)
  })

  it('submit button is disabled when terms not accepted', () => {
    render(
      <ReviewStep
        {...defaultProps}
        formData={{ ...baseFormData, acceptedTerms: false }}
      />
    )

    const submitButton = screen.getByText('auth:register.createAccount')
    expect(submitButton).toBeDisabled()
  })

  it('submit button calls onSubmit when terms are accepted and clicked', () => {
    const onSubmit = vi.fn()
    render(
      <ReviewStep
        {...defaultProps}
        formData={{ ...baseFormData, acceptedTerms: true }}
        onSubmit={onSubmit}
      />
    )

    const submitButton = screen.getByText('auth:register.createAccount')
    expect(submitButton).not.toBeDisabled()

    fireEvent.click(submitButton)
    expect(onSubmit).toHaveBeenCalledOnce()
  })
})
