import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

// i18n: return interpolation default string when provided, else the key.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// Router: stub Link to a plain anchor, navigate to a no-op.
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}))

// Mutation: never pending, no-op mutate.
vi.mock('@tanstack/react-query', () => ({
  useMutation: () => ({ mutate: vi.fn(), isPending: false }),
}))

// Feature deps → fixtures.
vi.mock('./api', () => ({
  createCompany: vi.fn(),
}))
vi.mock('./CompanyProvider', () => ({
  useInvalidateCompanies: () => vi.fn(),
}))
vi.mock('../../stores/companyStore', () => ({
  useCompanyStore: (selector: (s: { setCurrentCompany: () => void }) => unknown) =>
    selector({ setCurrentCompany: vi.fn() }),
}))

import { CompanyOnboardingPage } from './CompanyOnboardingPage'

describe('CompanyOnboardingPage (canonical primitives)', () => {
  it('renders exactly one h1 page title', () => {
    render(<CompanyOnboardingPage />)
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
  })

  it('exposes the first-step (country) options as real buttons', () => {
    render(<CompanyOnboardingPage />)
    // The country step renders one selectable button per country (France first).
    expect(
      screen.getByRole('button', { name: /France/ }),
    ).toBeInTheDocument()
  })

  it('renders the Next navigation control as a <button>', () => {
    render(<CompanyOnboardingPage />)
    const next = screen.getByRole('button', { name: 'common:next' })
    expect(next.tagName).toBe('BUTTON')
  })
})
