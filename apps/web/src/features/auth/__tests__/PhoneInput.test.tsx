import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { PhoneInput } from '../components/PhoneInput'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

describe('PhoneInput', () => {
  it('renders dial code prefix for given country', () => {
    render(
      <PhoneInput countryCode="FR" value="" onChange={vi.fn()} />
    )

    expect(screen.getByText('+33')).toBeInTheDocument()
  })

  it('calls onChange when user types', () => {
    const onChange = vi.fn()
    render(
      <PhoneInput countryCode="FR" value="" onChange={onChange} />
    )

    const input = screen.getByRole('textbox')
    fireEvent.change(input, { target: { value: '612345678' } })

    expect(onChange).toHaveBeenCalledWith('612345678')
  })

  it('renders without prefix when country has no dial code', () => {
    render(
      <PhoneInput countryCode="XX" value="" onChange={vi.fn()} />
    )

    // No dial code span should be rendered
    const spans = document.querySelectorAll('span.inline-flex')
    expect(spans).toHaveLength(0)
  })
})
