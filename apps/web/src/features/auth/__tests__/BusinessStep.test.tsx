import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { BusinessStep } from '../components/BusinessStep'

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

describe('BusinessStep', () => {
  const defaultProps = {
    formData: { countryCode: '', vertical: '' },
    errors: {} as Record<string, string | undefined>,
    updateField: vi.fn(),
  }

  it('renders country dropdown with options', () => {
    render(<BusinessStep {...defaultProps} />)

    const select = screen.getByRole('combobox')
    expect(select).toBeInTheDocument()

    // Should have a placeholder option
    expect(screen.getByText('auth:register.selectCountry')).toBeInTheDocument()
  })

  it('renders vertical cards for the current product', () => {
    render(<BusinessStep {...defaultProps} />)

    const listbox = screen.getByRole('listbox')
    expect(listbox).toBeInTheDocument()

    // IziPOS has 6 verticals: retail, pharmacy, coffee_shop, restaurant, fashion, parapharmacy
    // Query only button[role="option"] to avoid matching <option> elements in the Select
    const verticalCards = listbox.querySelectorAll('button[role="option"]')
    expect(verticalCards).toHaveLength(6)
  })

  it('calls updateField when a vertical card is clicked', () => {
    const updateField = vi.fn()
    render(<BusinessStep {...defaultProps} updateField={updateField} />)

    const listbox = screen.getByRole('listbox')
    const verticalCards = listbox.querySelectorAll('button[role="option"]')
    fireEvent.click(verticalCards[0])

    expect(updateField).toHaveBeenCalledWith('vertical', 'retail')
  })

  it('shows vertical error when present', () => {
    render(
      <BusinessStep
        {...defaultProps}
        errors={{ vertical: 'Vertical is required' }}
      />
    )

    expect(screen.getByText('auth:vertical.required')).toBeInTheDocument()
  })
})
