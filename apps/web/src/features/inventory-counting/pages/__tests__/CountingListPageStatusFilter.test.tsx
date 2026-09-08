import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CountingListPage } from '../CountingListPage'
import type { CountingFilters } from '../../types'

// The list select renders the raw i18n keys so we can read them back as the
// "displayed" value; capturing the mocked t() output is enough for the invariant.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockUseCountingList = vi.fn<(filters: CountingFilters) => unknown>()

vi.mock('../../api/queries', () => ({
  useCountingList: (filters: CountingFilters) => mockUseCountingList(filters),
}))

vi.mock('../../components/CountingStatusBadge', () => ({
  CountingStatusBadge: ({ status }: { status: string }) => (
    <span data-testid="status-badge">{status}</span>
  ),
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: () => <div>Error</div>,
}))

function renderPage(initialUrl: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialUrl]}>
        <CountingListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The <select> that drives the status filter (first select on the page). */
function statusSelect(): HTMLSelectElement {
  const [first] = screen.getAllByRole<HTMLSelectElement>('combobox')
  return first
}

/** The status value actually sent to the query hook on the first render. */
function appliedStatus(): CountingFilters['status'] {
  const [firstCall] = mockUseCountingList.mock.calls
  return firstCall[0].status
}

describe('CountingListPage — status filter URL sync (displayed === applied invariant)', () => {
  beforeEach(() => {
    mockUseCountingList.mockReset()
    mockUseCountingList.mockReturnValue({
      data: {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
        links: { first: '', last: '', prev: null, next: null },
      },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
  })

  it('no status param → select shows all AND request filter is all', () => {
    renderPage('/inventory/counting/list')

    expect(statusSelect().value).toBe('all')
    expect(appliedStatus()).toBe('all')
  })

  it('valid exact status (finalized) → select shows finalized AND request filter is finalized', () => {
    renderPage('/inventory/counting/list?status=finalized')

    expect(statusSelect().value).toBe('finalized')
    expect(appliedStatus()).toBe('finalized')
  })

  it('unknown status (bogus) → select falls back to all AND request filter is all (no phantom value)', () => {
    renderPage('/inventory/counting/list?status=bogus')

    // The lie the bug produced: select rendered "all" while the request carried
    // "bogus". Both must now be all.
    expect(statusSelect().value).toBe('all')
    expect(appliedStatus()).toBe('all')
  })

  it('dashboard "active" alias → select shows active AND request filter is active (option is displayable)', () => {
    renderPage('/inventory/counting/list?status=active')

    expect(statusSelect().value).toBe('active')
    expect(appliedStatus()).toBe('active')
    // The active option must be a real, selectable option in the dropdown.
    const optionValues = Array.from(statusSelect().options).map((o) => o.value)
    expect(optionValues).toContain('active')
  })

  it('dashboard "overdue" card (overdue=true) → select shows overdue AND request filter is overdue', () => {
    renderPage('/inventory/counting/list?overdue=true')

    expect(statusSelect().value).toBe('overdue')
    expect(appliedStatus()).toBe('overdue')
    const optionValues = Array.from(statusSelect().options).map((o) => o.value)
    expect(optionValues).toContain('overdue')
  })

  it('status=active + overdue=true → overdue wins (resolveStatusFromParams checks overdue first)', () => {
    renderPage('/inventory/counting/list?status=active&overdue=true')

    // Both params present: the overdue alias is the more specific intent, so the
    // select shows overdue and the request carries overdue — never active.
    expect(statusSelect().value).toBe('overdue')
    expect(appliedStatus()).toBe('overdue')
  })

  it('manual exact statuses stay honest (draft/cancelled)', () => {
    renderPage('/inventory/counting/list?status=cancelled')
    expect(statusSelect().value).toBe('cancelled')
    expect(appliedStatus()).toBe('cancelled')
  })
})
