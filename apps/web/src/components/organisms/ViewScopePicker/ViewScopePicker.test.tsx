import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ViewScopePicker } from './ViewScopePicker'

const setScope = vi.fn()
vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({
    scope: 'all' as const,
    effectiveLocationIds: ['a', 'b'],
    isAll: true,
    setScope,
  }),
}))
vi.mock('@/features/locations/hooks/useScopedLocations', () => ({
  useScopedLocations: () => ({
    data: [
      { id: 'a', name: 'Main', code: 'MAIN', type: 'shop', isDefault: true },
      { id: 'b', name: 'Warehouse', code: 'WH', type: 'warehouse', isDefault: false },
    ],
  }),
}))
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => ({
    'locations:viewScope.label': 'View scope',
    'locations:viewScope.all': 'All locations',
    'locations:viewScope.open': 'Choose locations',
  }[key] ?? key) }),
}))

describe('ViewScopePicker', () => {
  it('renders All locations and every allowed location', async () => {
    const user = userEvent.setup()
    render(<ViewScopePicker />)
    await user.click(screen.getByRole('button', { name: 'Choose locations' }))
    expect(screen.getByLabelText('All locations')).toBeInTheDocument()
    expect(screen.getByLabelText('Main')).toBeInTheDocument()
    expect(screen.getByLabelText('Warehouse')).toBeInTheDocument()
  })

  it('narrows scope when a location is selected and restores All locations', async () => {
    const user = userEvent.setup()
    render(<ViewScopePicker />)
    await user.click(screen.getByRole('button', { name: 'Choose locations' }))
    await user.click(screen.getByLabelText('Main'))
    expect(setScope).toHaveBeenCalledWith(['b'])
    await user.click(screen.getByLabelText('All locations'))
    expect(setScope).toHaveBeenCalledWith('all')
  })
})
