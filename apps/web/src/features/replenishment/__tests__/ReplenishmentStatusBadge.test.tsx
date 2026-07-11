import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { tokens } from '@/lib/designTokens'
import { ReplenishmentStatusBadge } from '../components/ReplenishmentStatusBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('ReplenishmentStatusBadge', () => {
  it.each([
    ['pending', tokens.badge.gray],
    ['in_progress', tokens.alert.info],
    ['fulfilled', tokens.alert.success],
    ['rejected', tokens.alert.error],
    ['cancelled', tokens.badge.gray],
  ] as const)('maps %s to its semantic StatusBadge tone', (status, expectedClass) => {
    render(<ReplenishmentStatusBadge status={status} />)

    expect(screen.getByText(`status.${status}`)).toHaveClass(expectedClass)
  })
})
