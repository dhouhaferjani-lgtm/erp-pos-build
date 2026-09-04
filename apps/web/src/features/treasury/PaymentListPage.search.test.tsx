import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { PaymentListPage } from './PaymentListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ to, children, ...props }: { to: string; children: ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../stores/authStore', () => {
  const authState = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: unknown) => unknown) => selector(authState)
  useAuthStore.getState = () => authState
  return { useAuthStore }
})
vi.mock('../../stores/companyStore', () => {
  const companyState = {
    currentCompanyId: 'company-1',
    getCurrentCompany: () => ({ currency: 'TND', locale: 'fr_FR' }),
  }
  const useCompanyStore = (selector: (s: unknown) => unknown) => selector(companyState)
  useCompanyStore.getState = () => companyState
  return { useCompanyStore }
})

// Capture every URL the list page asks the server for. The meta echoes the
// requested page over a two-page result set so the pagination bar's "next"
// button is enabled and page-two traversal is reachable from the test.
const apiGet = vi.fn((url: string) => {
  const requestedPage = Number(new URL(url, 'http://localhost').searchParams.get('page') ?? '1')
  return Promise.resolve({
    data: {
      data: [],
      meta: {
        current_page: requestedPage,
        last_page: 2,
        per_page: 25,
        total: 30,
        from: null,
        to: null,
      },
    },
  })
})
vi.mock('../../lib/api', () => ({
  api: {
    get: (url: string) => apiGet(url),
  },
}))

function renderWithClient(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

describe('PaymentListPage server-side search', () => {
  beforeEach(() => {
    apiGet.mockClear()
  })

  it('requests a bounded first page with no search param on first load', async () => {
    renderWithClient(<PaymentListPage />)
    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/payments?page=1&per_page=25')
    })
    // The pagination bar only mounts once the first page's meta has landed, so
    // this waits for the committed render rather than the request alone.
    await waitFor(() => {
      expect(screen.getByText('pagination.page 1 pagination.of 2')).toBeInTheDocument()
    })
  })

  it('sends the typed term as a ?search= query param to the server', async () => {
    const user = (await import('@testing-library/user-event')).default.setup()
    renderWithClient(<PaymentListPage />)

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/payments?page=1&per_page=25')
    })

    const input = screen.getByPlaceholderText('common:actions.search')
    await user.type(input, 'Alice')

    // SearchInput debounces (300ms) then the queryKey changes, refetching
    // with the search param appended.
    await waitFor(
      () => {
        expect(apiGet).toHaveBeenCalledWith('/payments?search=Alice&page=1&per_page=25')
      },
      { timeout: 2000 },
    )
  })

  it('restarts traversal at page one when the search term changes, without requesting the stale page', async () => {
    const user = (await import('@testing-library/user-event')).default.setup()
    renderWithClient(<PaymentListPage />)

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/payments?page=1&per_page=25')
    })

    // Page forward first: the reset only matters once an offset is in play.
    // The pagination bar only mounts once the first page's meta has landed.
    await waitFor(() => {
      expect(screen.getByText('pagination.page 1 pagination.of 2')).toBeInTheDocument()
    })
    await user.click(screen.getByRole('button', { name: 'pagination.next' }))
    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/payments?page=2&per_page=25')
    })

    const input = screen.getByPlaceholderText('common:actions.search')
    await user.type(input, 'Alice')

    await waitFor(
      () => {
        expect(apiGet).toHaveBeenCalledWith('/payments?search=Alice&page=1&per_page=25')
      },
      { timeout: 2000 },
    )

    // The reset must happen DURING render, before the query key is read — an
    // effect-based reset lets one request for the now-meaningless page two of
    // the filtered set escape to the server first.
    expect(apiGet).not.toHaveBeenCalledWith('/payments?search=Alice&page=2&per_page=25')
  })
})
