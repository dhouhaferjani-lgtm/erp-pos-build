import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi } from 'vitest'
import { PasswordStrength } from '../components/PasswordStrength'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

describe('PasswordStrength', () => {
  it('returns null when password is empty', () => {
    const { container } = render(<PasswordStrength password="" />)
    expect(container.innerHTML).toBe('')
  })

  it('shows all 5 requirement labels', () => {
    render(<PasswordStrength password="a" />)

    expect(screen.getByText('auth:passwordStrength.minLength')).toBeInTheDocument()
    expect(screen.getByText('auth:passwordStrength.lowercase')).toBeInTheDocument()
    expect(screen.getByText('auth:passwordStrength.uppercase')).toBeInTheDocument()
    expect(screen.getByText('auth:passwordStrength.number')).toBeInTheDocument()
    expect(screen.getByText('auth:passwordStrength.symbol')).toBeInTheDocument()
  })

  it('shows red segments for weak password (only lowercase)', () => {
    render(<PasswordStrength password="short" />)

    // Only lowercase met → 1 of 5 segments filled
    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-red-500'))
    const unfilled = Array.from(segments).filter((s) => s.className.includes('bg-gray-200'))

    expect(filled).toHaveLength(1)
    expect(unfilled).toHaveLength(4)
  })

  it('shows yellow segments for medium password', () => {
    // lowercase + uppercase + number = 3 requirements met
    render(<PasswordStrength password="Abc123" />)

    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-yellow-500'))

    expect(filled).toHaveLength(3)
  })

  it('shows all green segments for strong password', () => {
    // All requirements met: length >= 10, lower, upper, number, symbol
    render(<PasswordStrength password="Str0ng!Pass9" />)

    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-green-500'))

    expect(filled).toHaveLength(5)
  })
})
