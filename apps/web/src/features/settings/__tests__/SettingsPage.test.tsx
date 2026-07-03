import { describe, it, expect, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { SettingsPage } from '../SettingsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('SettingsPage', () => {
  it('links to the locations & branches management page', () => {
    renderWithProviders(<SettingsPage />)

    const card = screen.getByRole('link', { name: /sections\.locations/i })
    expect(card).toHaveAttribute('href', '/settings/locations')
  })
})
