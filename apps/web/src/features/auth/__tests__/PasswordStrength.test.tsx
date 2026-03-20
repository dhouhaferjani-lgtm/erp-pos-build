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

  it('shows 1 red segment and "weak" label for passwords < 8 chars', () => {
    render(<PasswordStrength password="short" />)

    expect(screen.getByText('auth:passwordStrength.weak')).toBeInTheDocument()

    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-red-500'))
    const unfilled = Array.from(segments).filter((s) => s.className.includes('bg-gray-200'))

    expect(filled).toHaveLength(1)
    expect(unfilled).toHaveLength(2)
  })

  it('shows 2 yellow segments and "fair" label for passwords 8-11 chars', () => {
    render(<PasswordStrength password="eightchr" />)

    expect(screen.getByText('auth:passwordStrength.fair')).toBeInTheDocument()

    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-yellow-500'))
    const unfilled = Array.from(segments).filter((s) => s.className.includes('bg-gray-200'))

    expect(filled).toHaveLength(2)
    expect(unfilled).toHaveLength(1)
  })

  it('shows 3 green segments and "strong" label for passwords >= 12 chars', () => {
    render(<PasswordStrength password="strongpassword123" />)

    expect(screen.getByText('auth:passwordStrength.strong')).toBeInTheDocument()

    const segments = document.querySelectorAll('.rounded-full')
    const filled = Array.from(segments).filter((s) => s.className.includes('bg-green-500'))

    expect(filled).toHaveLength(3)
  })
})
