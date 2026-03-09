import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CountingListPage } from '../CountingListPage'

// Track translation keys used
const usedKeys: string[] = []

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      usedKeys.push(key)
      return key
    },
  }),
}))

const mockUseCountingList = vi.fn()

vi.mock('../../api/queries', () => ({
  useCountingList: (...args: unknown[]) => mockUseCountingList(...args),
}))

vi.mock('../../components/CountingStatusBadge', () => ({
  CountingStatusBadge: ({ status }: { status: string }) => (
    <span data-testid="status-badge">{status}</span>
  ),
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: () => <div>Error</div>,
}))

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CountingListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const mockCountingData = {
  data: [
    {
      id: 1,
      uuid: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      scope_type: 'product',
      title: null,
      status: 'count_1_in_progress',
      created_on_mobile: true,
      progress: { overall: 50 },
      created_at: '2026-03-01T00:00:00Z',
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
  links: { first: '', last: '', prev: null, next: null },
}

describe('CountingListPage', () => {
  beforeEach(() => {
    usedKeys.length = 0
    mockUseCountingList.mockReturnValue({
      data: mockCountingData,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
  })

  it('renders with translated filter labels', () => {
    renderPage()

    expect(usedKeys).toContain('counting.list.sourceFilter.all')
    expect(usedKeys).toContain('counting.list.sourceFilter.mobileOnly')
    expect(usedKeys).toContain('counting.list.sourceFilter.webOnly')
  })

  it('renders mobile badge with translation key', () => {
    renderPage()

    expect(usedKeys).toContain('counting.list.mobileBadge')
  })

  it('renders empty state with translation key', () => {
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

    renderPage()

    expect(usedKeys).toContain('counting.list.empty')
  })
})
