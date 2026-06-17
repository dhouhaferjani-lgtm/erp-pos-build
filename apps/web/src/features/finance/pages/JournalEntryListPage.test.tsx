import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { JournalEntry } from '../types'

const navigateMock = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => navigateMock,
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

const useJournalEntriesMock = vi.fn()
vi.mock('../hooks/useJournalEntries', () => ({
  useJournalEntries: (page?: number) => useJournalEntriesMock(page) as unknown,
}))

import { JournalEntryListPage } from './JournalEntryListPage'

function makeEntry(overrides: Partial<JournalEntry> = {}): JournalEntry {
  return {
    id: 'je-1',
    tenant_id: 't-1',
    entry_number: 'JE-0001',
    entry_date: '2026-06-14',
    description: 'Opening balance',
    status: 'posted',
    source_type: null,
    source_id: null,
    lines: [],
    created_at: '2026-06-14T00:00:00Z',
    updated_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

describe('JournalEntryListPage', () => {
  beforeEach(() => {
    navigateMock.mockReset()
    useJournalEntriesMock.mockReset()
  })

  it('renders exactly one h1', () => {
    useJournalEntriesMock.mockReturnValue({
      data: { data: [makeEntry()], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
      isLoading: false,
    })

    render(<JournalEntryListPage />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the journal-entry status through a StatusBadge pill', () => {
    useJournalEntriesMock.mockReturnValue({
      data: { data: [makeEntry({ status: 'posted' })], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
      isLoading: false,
    })

    const { container } = render(<JournalEntryListPage />)

    const pill = container.querySelector('span.rounded-full')
    expect(pill).not.toBeNull()
    expect(pill?.className).toContain('rounded-full')
  })

  it('navigates to the create route when the add control is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event')
    useJournalEntriesMock.mockReturnValue({
      data: { data: [makeEntry()], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
      isLoading: false,
    })

    render(<JournalEntryListPage />)

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /journalEntry\.new/i }))

    expect(navigateMock).toHaveBeenCalledWith('/finance/journal-entries/create')
  })
})
