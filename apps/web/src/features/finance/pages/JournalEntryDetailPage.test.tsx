import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { JournalEntryDetailPage } from './JournalEntryDetailPage'

// i18n mock: return the interpolation string when one is supplied, else the key.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

const { mockUseJournalEntry, mockMutate } = vi.hoisted(() => ({
  mockUseJournalEntry: vi.fn(),
  mockMutate: vi.fn(),
}))

vi.mock('../hooks/useJournalEntries', () => ({
  useJournalEntry: () => mockUseJournalEntry(),
}))

vi.mock('../hooks/useJournalEntryMutations', () => ({
  usePostJournalEntry: () => ({
    mutate: mockMutate,
    isPending: false,
    error: null,
    isSuccess: false,
  }),
}))

const draftEntry = {
  id: 'je-draft',
  entry_number: 'JE-2025-000002',
  entry_date: '2025-01-16',
  description: 'Draft entry',
  status: 'draft' as const,
  lines: [
    {
      id: 'line-1',
      account_id: 'acc-1',
      account_code: '1100',
      account_name: 'Cash',
      debit: '500.00',
      credit: '0.00',
      description: null,
    },
    {
      id: 'line-2',
      account_id: 'acc-3',
      account_code: '2000',
      account_name: 'Accounts Payable',
      debit: '0.00',
      credit: '500.00',
      description: null,
    },
  ],
  created_at: '2025-01-16T14:30:00Z',
  source_type: null,
  source_id: null,
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/finance/journal-entries/je-draft']}>
      <Routes>
        <Route
          path="/finance/journal-entries/:id"
          element={<JournalEntryDetailPage />}
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('JournalEntryDetailPage (canonicalized)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseJournalEntry.mockReturnValue({ data: draftEntry, isLoading: false })
  })

  it('renders exactly one h1 via PageHeader', () => {
    renderPage()
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('JE-2025-000002')
  })

  it('renders the status via the shared StatusBadge pill', () => {
    const { container } = renderPage()
    const pill = container.querySelector('span.rounded-full')
    expect(pill).not.toBeNull()
    expect(pill).toHaveTextContent('finance:journalEntry.status.draft')
  })

  it('right-aligns debit/credit cells with tabular-nums', () => {
    const { container } = renderPage()
    const numericCell = container.querySelector('td.tabular-nums')
    expect(numericCell).not.toBeNull()
    expect(numericCell?.className).toContain('text-end')
  })

  it('links document-backed sources to the source document', () => {
    mockUseJournalEntry.mockReturnValue({
      data: { ...draftEntry, source_type: 'invoice', source_id: 'inv-1' },
      isLoading: false,
    })

    renderPage()

    expect(screen.getByRole('link', { name: 'invoice' })).toHaveAttribute('href', '/sales/invoices/inv-1')
  })

  it('renders non-document sources as a plain badge', () => {
    mockUseJournalEntry.mockReturnValue({
      data: { ...draftEntry, source_type: 'payment', source_id: 'pay-1' },
      isLoading: false,
    })

    const { container } = renderPage()

    expect(screen.queryByRole('link', { name: 'payment' })).not.toBeInTheDocument()
    const sourceBadge = container.querySelector('[data-testid="journal-source-badge"]')
    expect(sourceBadge).toHaveTextContent('payment')
  })
})
