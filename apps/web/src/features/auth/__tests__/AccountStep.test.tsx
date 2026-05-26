import { render, screen, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { AccountStep } from '../components/AccountStep'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

// T6 Phase 0a removed the global check-email debounce probe from AccountStep,
// so the component no longer uses @tanstack/react-query. No mock needed.

describe('AccountStep', () => {
  const defaultProps = {
    formData: { name: '', email: '', password: '' },
    errors: {} as Record<string, string | undefined>,
    updateField: vi.fn(),
  }

  it('renders name, email, and password fields', () => {
    render(<AccountStep {...defaultProps} />)

    expect(screen.getByLabelText(/auth:register.name/)).toBeInTheDocument()
    expect(screen.getByLabelText(/auth:register.email/)).toBeInTheDocument()
    expect(screen.getByLabelText(/auth:register.password/)).toBeInTheDocument()
  })

  it('shows error messages when errors prop has values', () => {
    render(
      <AccountStep
        {...defaultProps}
        errors={{
          name: 'Name is required',
          email: 'Email is required',
          password: 'Password is required',
        }}
      />
    )

    expect(screen.getByText('Name is required')).toBeInTheDocument()
    expect(screen.getByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Password is required')).toBeInTheDocument()
  })

  it('password toggle switches between show/hide', () => {
    render(
      <AccountStep
        {...defaultProps}
        formData={{ name: '', email: '', password: 'test123' }}
      />
    )

    const passwordInput = screen.getByLabelText(/auth:register.password/)
    expect(passwordInput).toHaveAttribute('type', 'password')

    // Find the toggle button (the button with tabIndex -1 inside the password field wrapper)
    const toggleButton = passwordInput.parentElement?.querySelector('button')
    expect(toggleButton).toBeTruthy()

    fireEvent.click(toggleButton!)
    expect(passwordInput).toHaveAttribute('type', 'text')

    fireEvent.click(toggleButton!)
    expect(passwordInput).toHaveAttribute('type', 'password')
  })
})
