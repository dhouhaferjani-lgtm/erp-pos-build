import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: unknown) => (typeof opts === 'string' ? opts : key),
  }),
}))

vi.mock('../../hooks/usePageTitle', () => ({
  usePageTitle: () => undefined,
}))

import { SettingsPage } from './SettingsPage'

function renderPage() {
  return render(
    <MemoryRouter>
      <SettingsPage />
    </MemoryRouter>,
  )
}

describe('SettingsPage', () => {
  it('renders exactly one h1 (the canonical PageHeader title)', () => {
    renderPage()

    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('title')
  })

  it('renders a settings section as a navigable link', () => {
    renderPage()

    const usersLink = screen.getByRole('link', { name: /sections\.users\.title/ })
    expect(usersLink).toHaveAttribute('href', '/settings/users')
  })

  it('renders a HubCard link for every settings section', () => {
    renderPage()

    // Each section becomes one navigable HubCard link.
    expect(
      screen.getByRole('link', { name: /sections\.roles\.title/ }),
    ).toHaveAttribute('href', '/settings/roles')
    expect(
      screen.getByRole('link', { name: /sections\.pos\.title/ }),
    ).toHaveAttribute('href', '/pos/terminals')
  })
})
