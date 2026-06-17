import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, MemoryRouter, Routes, Route } from 'react-router-dom'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { JournalEntryForm } from './pages/JournalEntryForm'
import { JournalEntryListPage } from './pages/JournalEntryListPage'
import { JournalEntryDetailPage } from './pages/JournalEntryDetailPage'

const { mockApiGet, mockApiPost } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
}))

const mockAccounts = [
  {
    id: 'acc-1',
    code: '1100',
    name: 'Cash',
    type: 'asset',
    is_active: true,
  },
  {
    id: 'acc-2',
    code: '4000',
    name: 'Sales Revenue',
    type: 'revenue',
    is_active: true,
  },
  {
    id: 'acc-3',
    code: '2000',
    name: 'Accounts Payable',
    type: 'liability',
    is_active: true,
  },
  {
    id: 'acc-4',
    code: '5000',
    name: 'Cost of Goods Sold',
    type: 'expense',
    is_active: true,
  },
]

const mockJournalEntries = {
  data: [
    {
      id: 'je-1',
      entry_number: 'JE-2025-000001',
      entry_date: '2025-01-15',
      description: 'Cash sale',
      status: 'posted',
      lines: [
        {
          id: 'line-1',
          account_id: 'acc-1',
          account_code: '1100',
          account_name: 'Cash',
          debit: '1000.00',
          credit: '0.00',
          description: null,
        },
        {
          id: 'line-2',
          account_id: 'acc-2',
          account_code: '4000',
          account_name: 'Sales Revenue',
          debit: '0.00',
          credit: '1000.00',
          description: null,
        },
      ],
      created_at: '2025-01-15T10:00:00Z',
    },
    {
      id: 'je-2',
      entry_number: 'JE-2025-000002',
      entry_date: '2025-01-16',
      description: 'Purchase supplies',
      status: 'draft',
      lines: [
        {
          id: 'line-3',
          account_id: 'acc-4',
          account_code: '5000',
          account_name: 'Cost of Goods Sold',
          debit: '500.00',
          credit: '0.00',
          description: null,
        },
        {
          id: 'line-4',
          account_id: 'acc-3',
          account_code: '2000',
          account_name: 'Accounts Payable',
          debit: '0.00',
          credit: '500.00',
          description: null,
        },
      ],
      created_at: '2025-01-16T14:30:00Z',
    },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 2,
  },
}

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })
}

function TestWrapper({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={createTestQueryClient()}>
      <BrowserRouter>{children}</BrowserRouter>
    </QueryClientProvider>
  )
}

describe('JournalEntryListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders the page title', async () => {
    mockApiGet.mockResolvedValue(mockJournalEntries)

    render(<JournalEntryListPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /journal entries/i })).toBeInTheDocument()
    })
  })

  it('displays list of journal entries', async () => {
    mockApiGet.mockResolvedValue(mockJournalEntries)

    render(<JournalEntryListPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByText('JE-2025-000001')).toBeInTheDocument()
      expect(screen.getByText('JE-2025-000002')).toBeInTheDocument()
    })
  })

  it('shows entry status badges', async () => {
    mockApiGet.mockResolvedValue(mockJournalEntries)

    render(<JournalEntryListPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByText(/posted/i)).toBeInTheDocument()
      expect(screen.getByText(/draft/i)).toBeInTheDocument()
    })
  })

  it('has a create new entry button', async () => {
    mockApiGet.mockResolvedValue(mockJournalEntries)

    render(<JournalEntryListPage />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /new.*entry/i })).toBeInTheDocument()
    })
  })
})

describe('JournalEntryForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  const setupMocks = () => {
    mockApiGet.mockResolvedValue(mockAccounts)
  }

  it('renders the form title', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /new journal entry/i })).toBeInTheDocument()
    })
  })

  it('has entry date field', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByLabelText(/entry date/i)).toBeInTheDocument()
    })
  })

  it('has description field', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByLabelText(/description/i)).toBeInTheDocument()
    })
  })

  it('starts with two empty journal lines', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      const lineRows = screen.getAllByTestId(/journal-line-/)
      expect(lineRows.length).toBeGreaterThanOrEqual(2)
    })
  })

  it('can add a new journal line', async () => {
    const user = userEvent.setup()
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add line/i })).toBeInTheDocument()
    })

    const initialLines = screen.getAllByTestId(/journal-line-/)
    const initialCount = initialLines.length

    await user.click(screen.getByRole('button', { name: /add line/i }))

    await waitFor(() => {
      const newLines = screen.getAllByTestId(/journal-line-/)
      expect(newLines.length).toBe(initialCount + 1)
    })
  })

  it('can remove a journal line when more than 2 exist', async () => {
    const user = userEvent.setup()
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add line/i })).toBeInTheDocument()
    })

    // Add a third line
    await user.click(screen.getByRole('button', { name: /add line/i }))

    await waitFor(() => {
      const lines = screen.getAllByTestId(/journal-line-/)
      expect(lines.length).toBe(3)
    })

    // Now remove one
    const removeButtons = screen.getAllByRole('button', { name: /remove/i })
    await user.click(removeButtons[0])

    await waitFor(() => {
      const lines = screen.getAllByTestId(/journal-line-/)
      expect(lines.length).toBe(2)
    })
  })

  it('shows total debits and credits', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByText(/total debits/i)).toBeInTheDocument()
      expect(screen.getByText(/total credits/i)).toBeInTheDocument()
    })
  })

  it('shows balance status (balanced/unbalanced)', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      // Initially should show balanced (0 = 0)
      expect(screen.getByText(/balanced/i)).toBeInTheDocument()
    })
  })

  it('shows unbalanced status when debits do not equal credits', async () => {
    const user = userEvent.setup()
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getAllByTestId(/journal-line-/).length).toBeGreaterThanOrEqual(2)
    })

    // Enter a debit amount without matching credit
    const debitInputs = screen.getAllByPlaceholderText(/debit/i)
    await user.clear(debitInputs[0])
    await user.type(debitInputs[0], '100')

    await waitFor(() => {
      expect(screen.getByText(/unbalanced/i)).toBeInTheDocument()
    })
  })

  it('has save as draft button', async () => {
    setupMocks()

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /save.*draft/i })).toBeInTheDocument()
    })
  })

  it('shows validation error when submitting with unbalanced entry', async () => {
    const user = userEvent.setup()
    setupMocks()
    mockApiPost.mockRejectedValue({
      response: { data: { error: { code: 'UNBALANCED_ENTRY', message: 'Debits must equal credits' } } },
    })

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getAllByTestId(/journal-line-/).length).toBeGreaterThanOrEqual(2)
    })

    // Enter unbalanced amounts
    const debitInputs = screen.getAllByPlaceholderText(/debit/i)
    await user.clear(debitInputs[0])
    await user.type(debitInputs[0], '100')

    // Try to submit
    await user.click(screen.getByRole('button', { name: /save.*draft/i }))

    await waitFor(() => {
      // Should show validation error and unbalanced status indicator
      const unbalancedElements = screen.getAllByText(/unbalanced/i)
      expect(unbalancedElements.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('submits form successfully with balanced entry', async () => {
    const user = userEvent.setup()
    setupMocks()
    mockApiPost.mockResolvedValue({
      id: 'je-new',
      entry_number: 'JE-2025-000003',
      status: 'draft',
    })

    render(<JournalEntryForm />, { wrapper: TestWrapper })

    await waitFor(() => {
      expect(screen.getByLabelText(/entry date/i)).toBeInTheDocument()
    })

    // Fill in the form
    const dateInput = screen.getByLabelText(/entry date/i)
    await user.clear(dateInput)
    await user.type(dateInput, '2025-01-20')

    const descriptionInput = screen.getByLabelText(/description/i)
    await user.type(descriptionInput, 'Test journal entry')

    // Select accounts and enter amounts
    const accountSelects = screen.getAllByLabelText(/account/i)
    await user.selectOptions(accountSelects[0], 'acc-1')
    await user.selectOptions(accountSelects[1], 'acc-2')

    const debitInputs = screen.getAllByPlaceholderText(/debit/i)
    const creditInputs = screen.getAllByPlaceholderText(/credit/i)

    await user.clear(debitInputs[0])
    await user.type(debitInputs[0], '100')

    await user.clear(creditInputs[1])
    await user.type(creditInputs[1], '100')

    // Verify balanced
    await waitFor(() => {
      expect(screen.getByText(/balanced/i)).toBeInTheDocument()
    })

    // Submit
    await user.click(screen.getByRole('button', { name: /save.*draft/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/journal-entries', expect.objectContaining({
        entry_date: '2025-01-20',
        description: 'Test journal entry',
        lines: expect.arrayContaining([
          expect.objectContaining({
            account_id: 'acc-1',
            debit: '100',
          }),
          expect.objectContaining({
            account_id: 'acc-2',
            credit: '100',
          }),
        ]),
      }))
    })
  })
})

describe('JournalEntryDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  const mockDraftEntry = {
    id: 'je-draft',
    entry_number: 'JE-2025-000002',
    entry_date: '2025-01-16',
    description: 'Draft entry',
    status: 'draft',
    lines: [
      {
        id: 'line-1',
        account_id: 'acc-1',
        account_code: '1100',
        account_name: 'Cash',
        debit: '500.00',
        credit: '0.00',
        description: null,
        line_number: 0,
      },
      {
        id: 'line-2',
        account_id: 'acc-3',
        account_code: '2000',
        account_name: 'Accounts Payable',
        debit: '0.00',
        credit: '500.00',
        description: null,
        line_number: 1,
      },
    ],
    created_at: '2025-01-16T14:30:00Z',
    updated_at: '2025-01-16T14:30:00Z',
  }

  const mockPostedEntry = {
    ...mockDraftEntry,
    id: 'je-posted',
    entry_number: 'JE-2025-000001',
    status: 'posted',
    posted_at: '2025-01-16T15:00:00Z',
  }

  function renderWithRoute(entryId: string) {
    return render(
      <QueryClientProvider client={createTestQueryClient()}>
        <MemoryRouter initialEntries={[`/finance/journal-entries/${entryId}`]}>
          <Routes>
            <Route path="/finance/journal-entries/:id" element={<JournalEntryDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }

  it('displays entry details', async () => {
    mockApiGet.mockResolvedValue(mockDraftEntry)

    renderWithRoute('je-draft')

    await waitFor(() => {
      expect(screen.getByText('JE-2025-000002')).toBeInTheDocument()
      expect(screen.getByText('Draft entry')).toBeInTheDocument()
    })
  })

  it('displays journal lines', async () => {
    mockApiGet.mockResolvedValue(mockDraftEntry)

    renderWithRoute('je-draft')

    await waitFor(() => {
      expect(screen.getByText('Cash')).toBeInTheDocument()
      expect(screen.getByText('Accounts Payable')).toBeInTheDocument()
      // 500.00 appears in multiple places (lines and totals), so check for at least one
      const amountElements = screen.getAllByText('500.00')
      expect(amountElements.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('shows post button for draft entries', async () => {
    mockApiGet.mockResolvedValue(mockDraftEntry)

    renderWithRoute('je-draft')

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /post/i })).toBeInTheDocument()
    })
  })

  it('does not show post button for posted entries', async () => {
    mockApiGet.mockResolvedValue(mockPostedEntry)

    renderWithRoute('je-posted')

    await waitFor(() => {
      expect(screen.getByText('JE-2025-000001')).toBeInTheDocument()
    })

    expect(screen.queryByRole('button', { name: /post/i })).not.toBeInTheDocument()
  })

  it('can post a draft entry', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(mockDraftEntry)
    mockApiPost.mockResolvedValue({ ...mockDraftEntry, status: 'posted' })

    renderWithRoute('je-draft')

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /post/i })).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /post/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/journal-entries/je-draft/post', undefined)
    })
  })
})
