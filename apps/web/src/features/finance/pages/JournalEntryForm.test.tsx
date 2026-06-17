import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'

// i18n: echo the key, or the interpolation string when one is provided.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// Router: stub Link so we don't need a real router context.
vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => (
    <a href={to}>{children}</a>
  ),
}))

const { mockUseAccounts, mockUseCreateJournalEntry, mockUseCurrency } =
  vi.hoisted(() => ({
    mockUseAccounts: vi.fn(),
    mockUseCreateJournalEntry: vi.fn(),
    mockUseCurrency: vi.fn(),
  }))

vi.mock('../hooks/useAccounts', () => ({
  useAccounts: mockUseAccounts,
}))

vi.mock('../hooks/useJournalEntryMutations', () => ({
  useCreateJournalEntry: mockUseCreateJournalEntry,
}))

// Preserve getDecimals (used by MoneyInput) while overriding the hook.
vi.mock('../../../hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../hooks/useCurrency')>()
  return {
    ...actual,
    useCurrency: mockUseCurrency,
  }
})

import { JournalEntryForm } from './JournalEntryForm'

const mockAccounts = [
  { id: 'acc-1', code: '1100', name: 'Cash', type: 'asset', is_active: true },
  { id: 'acc-2', code: '4000', name: 'Sales', type: 'revenue', is_active: true },
]

describe('JournalEntryForm (atoms migration)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseAccounts.mockReturnValue({ data: mockAccounts, isLoading: false })
    mockUseCurrency.mockReturnValue({ currency: 'TND' })
    mockUseCreateJournalEntry.mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
      error: null,
    })
  })

  it('renders exactly one h1 page title', () => {
    const { container } = render(<JournalEntryForm />)
    const headings = container.querySelectorAll('h1')
    expect(headings.length).toBe(1)
    expect(headings[0]).toHaveTextContent(/journalEntry\.form\.title/i)
  })

  it('renders the entry date and description fields', () => {
    render(<JournalEntryForm />)
    expect(
      screen.getByLabelText(/journalEntry\.entryDate/i),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText(/journalEntry\.description/i),
    ).toBeInTheDocument()
  })

  it('renders a submit button of type submit', () => {
    render(<JournalEntryForm />)
    const submit = screen
      .getAllByRole('button')
      .find((b) => b.getAttribute('type') === 'submit')
    expect(submit).toBeDefined()
  })

  it('renders debit and credit money inputs (number type)', () => {
    render(<JournalEntryForm />)
    const debitInputs = screen.getAllByLabelText(/journalEntry\.debit/i)
    const creditInputs = screen.getAllByLabelText(/journalEntry\.credit/i)
    expect(debitInputs.length).toBeGreaterThanOrEqual(1)
    expect(creditInputs.length).toBeGreaterThanOrEqual(1)
    expect(debitInputs[0]).toHaveAttribute('type', 'number')
  })
})
