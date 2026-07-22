import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BrowserRouter } from 'react-router-dom'
import { RepositoryMovementsTab } from '../components/RepositoryMovementsTab'
import { formatCurrency } from '@/lib/format'
import type { RepositoryMovement, RepositoryMovementsResponse } from '../hooks/useRepositoryMovements'

// Mirrors RepositoryDetailPage.test.tsx: real i18n resources are large and
// locale-dependent, so tests assert on the raw key (t returns the key, or the
// string fallback when one is passed as the second arg — e.g. t(key, 'foo')).
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockUseRepositoryMovements = vi.hoisted(() => vi.fn())

vi.mock('../hooks/useRepositoryMovements', async () => {
  const actual = await vi.importActual<typeof import('../hooks/useRepositoryMovements')>(
    '../hooks/useRepositoryMovements',
  )
  return {
    ...actual,
    useRepositoryMovements: (...args: unknown[]) => mockUseRepositoryMovements(...args) as unknown,
  }
})

vi.mock('@/stores/companyStore', () => {
  const companyState = {
    currentCompanyId: 'company-1',
    getCurrentCompany: () => ({ currency: 'TND', locale: 'fr_FR' }),
  }
  const useCompanyStore = Object.assign(
    (selector: (state: typeof companyState) => unknown) => selector(companyState),
    { getState: () => companyState },
  )
  return { useCompanyStore }
})

function renderTab(repositoryId = 'repo-1') {
  return render(
    <BrowserRouter>
      <RepositoryMovementsTab repositoryId={repositoryId} />
    </BrowserRouter>,
  )
}

const paymentMovement: RepositoryMovement = {
  id: 'mov-1',
  direction: 'in',
  amount: '250.500',
  currency: 'TND',
  balance_after: '1250.500',
  ordinal: 42,
  source_type: 'payment',
  source_id: 'pay-1',
  journal_entry_id: 'je-1',
  reason_code: null,
  occurred_at: '2026-07-01T10:00:00Z',
  recorded_while_frozen: false,
}

const adjustmentMovement: RepositoryMovement = {
  id: 'mov-2',
  direction: 'out',
  amount: '10.000',
  currency: 'TND',
  balance_after: '1240.500',
  ordinal: 43,
  source_type: 'adjustment',
  source_id: 'adj-1',
  journal_entry_id: null,
  reason_code: 'count_variance',
  occurred_at: '2026-07-02T09:00:00Z',
  recorded_while_frozen: true,
}

const expenseMovement: RepositoryMovement = {
  id: 'mov-3',
  direction: 'out',
  amount: '5.250',
  currency: 'TND',
  balance_after: '1235.250',
  ordinal: 44,
  source_type: 'expense',
  source_id: 'exp-1',
  journal_entry_id: 'je-2',
  reason_code: null,
  occurred_at: '2026-07-03T08:00:00Z',
  recorded_while_frozen: false,
}

function baseResponse(overrides?: Partial<RepositoryMovementsResponse>): RepositoryMovementsResponse {
  return {
    data: [paymentMovement, adjustmentMovement, expenseMovement],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 3, from: 1, to: 3 },
    ...overrides,
  }
}

describe('RepositoryMovementsTab', () => {
  beforeEach(() => {
    mockUseRepositoryMovements.mockReset()
    mockUseRepositoryMovements.mockReturnValue({
      data: baseResponse(),
      isLoading: false,
      error: null,
    })
  })

  it('calls useRepositoryMovements with the repository id and an initial page filter', () => {
    renderTab('repo-1')

    expect(mockUseRepositoryMovements).toHaveBeenCalledWith('repo-1', expect.objectContaining({ page: 1 }))
  })

  it('renders a loading state', () => {
    mockUseRepositoryMovements.mockReturnValue({ data: undefined, isLoading: true, error: null })
    renderTab()

    expect(screen.getByText('common:status.loading')).toBeInTheDocument()
  })

  it('renders an error state with a retry affordance wired to refetch', async () => {
    const refetch = vi.fn()
    mockUseRepositoryMovements.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('boom'),
      refetch,
    })
    const user = userEvent.setup()
    renderTab()

    expect(screen.getByText('treasury:repositories.movements.loadError')).toBeInTheDocument()

    const retryButton = screen.getByRole('button', { name: 'actions.tryAgain' })
    await user.click(retryButton)

    expect(refetch).toHaveBeenCalledTimes(1)
  })

  it('renders an empty state when there are no movements', () => {
    mockUseRepositoryMovements.mockReturnValue({
      data: baseResponse({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null } }),
      isLoading: false,
      error: null,
    })
    renderTab()

    expect(screen.getByText('treasury:repositories.movements.empty.title')).toBeInTheDocument()
  })

  it('renders amounts via formatCurrency with a direction sign, never a raw parseFloat/Number', () => {
    renderTab()

    const expectedIn = formatCurrency('250.500', { currency: 'TND', locale: 'fr-FR' })
    const expectedOut = formatCurrency('10.000', { currency: 'TND', locale: 'fr-FR' })

    const content = document.body.textContent ?? ''
    expect(content).toContain(`+${expectedIn}`)
    expect(content).toContain(`-${expectedOut}`)
  })

  it('renders balance_after via formatCurrency', () => {
    renderTab()

    const expected = formatCurrency('1250.500', { currency: 'TND', locale: 'fr-FR' })
    expect(document.body.textContent).toContain(expected)
  })

  it('links a payment-sourced movement to the payment detail route', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-1')
    const link = within(row).getByRole('link', { name: /pay-1/i })
    expect(link).toHaveAttribute('href', '/treasury/payments/pay-1')
  })

  it('links an expense-sourced movement to the expense view route', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-3')
    const link = within(row).getByRole('link', { name: /exp-1/i })
    expect(link).toHaveAttribute('href', '/expenses/exp-1/view')
  })

  it('renders a copy affordance (not an invented route) for source types with no established page', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-2')
    expect(within(row).queryByRole('link', { name: /adj-1/i })).not.toBeInTheDocument()
    expect(within(row).getByText('adj-1')).toBeInTheDocument()
    expect(
      within(row).getByRole('button', { name: 'treasury:repositories.movements.copyId' }),
    ).toBeInTheDocument()
  })

  it('links to the journal entry when journal_entry_id is present', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-1')
    const link = within(row).getByRole('link', { name: /je-1/i })
    expect(link).toHaveAttribute('href', '/finance/journal-entries/je-1')
  })

  it('renders a dash (no invented link) when journal_entry_id is null', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-2')
    expect(within(row).queryByRole('link', { name: /je-/i })).not.toBeInTheDocument()
  })

  it('surfaces the recorded_while_frozen flag', () => {
    renderTab()

    const row = screen.getByTestId('movement-row-mov-2')
    expect(within(row).getByText('treasury:repositories.movements.frozenBadge')).toBeInTheDocument()
  })

  it('wires the direction filter to the hook, resetting to page 1', async () => {
    const user = userEvent.setup()
    renderTab()

    await user.selectOptions(
      screen.getByLabelText('treasury:repositories.movements.filters.direction'),
      'out',
    )

    expect(mockUseRepositoryMovements).toHaveBeenLastCalledWith(
      'repo-1',
      expect.objectContaining({ direction: 'out', page: 1 }),
    )
  })

  it('wires the source-type filter to the hook', async () => {
    const user = userEvent.setup()
    renderTab()

    await user.selectOptions(
      screen.getByLabelText('treasury:repositories.movements.filters.sourceType'),
      'payment',
    )

    expect(mockUseRepositoryMovements).toHaveBeenLastCalledWith(
      'repo-1',
      expect.objectContaining({ source_type: 'payment', page: 1 }),
    )
  })

  it('wires date_from / date_to filters (shared DateRangeFilter) to the hook, resetting to page 1', () => {
    renderTab()

    // DateRangeFilter renders two date inputs via common:dateFrom/dateTo
    // placeholders rather than per-input <label htmlFor>.
    const dateFrom = screen.getByPlaceholderText('dateFrom')
    fireEvent.change(dateFrom, { target: { value: '2026-07-01' } })

    expect(mockUseRepositoryMovements).toHaveBeenLastCalledWith(
      'repo-1',
      expect.objectContaining({ date_from: '2026-07-01', page: 1 }),
    )

    const dateTo = screen.getByPlaceholderText('dateTo')
    fireEvent.change(dateTo, { target: { value: '2026-07-05' } })

    expect(mockUseRepositoryMovements).toHaveBeenLastCalledWith(
      'repo-1',
      expect.objectContaining({ date_to: '2026-07-05', page: 1 }),
    )
  })

  it('paginates via the shared OffsetPagination, requesting the next server page', async () => {
    mockUseRepositoryMovements.mockReturnValue({
      data: baseResponse({ meta: { current_page: 1, last_page: 3, per_page: 20, total: 60, from: 1, to: 20 } }),
      isLoading: false,
      error: null,
    })
    const user = userEvent.setup()
    renderTab()

    // OffsetPagination's t() calls omit the 'common:' namespace prefix (it
    // calls useTranslation('common') then t('pagination.next') directly), so
    // under the shared i18n test mock the accessible name is the bare key.
    await user.click(screen.getByRole('button', { name: 'pagination.next' }))

    expect(mockUseRepositoryMovements).toHaveBeenLastCalledWith(
      'repo-1',
      expect.objectContaining({ page: 2 }),
    )
  })

  it('renders the shared OffsetPagination with the per-page selector hidden (server hardcodes per_page)', () => {
    mockUseRepositoryMovements.mockReturnValue({
      data: baseResponse({ meta: { current_page: 1, last_page: 3, per_page: 20, total: 60, from: 1, to: 20 } }),
      isLoading: false,
      error: null,
    })
    renderTab()

    expect(screen.getByRole('button', { name: 'pagination.previous' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'pagination.next' })).toBeInTheDocument()
    // Only the direction + source-type filter selects remain — the
    // OffsetPagination per-page combobox is suppressed via hidePerPage.
    expect(screen.getAllByRole('combobox')).toHaveLength(2)
    expect(screen.queryByText('pagination.rowsPerPage:')).not.toBeInTheDocument()
  })

  it('disables previous on the first page and next on the last page', () => {
    mockUseRepositoryMovements.mockReturnValue({
      data: baseResponse({ meta: { current_page: 1, last_page: 1, per_page: 20, total: 3, from: 1, to: 3 } }),
      isLoading: false,
      error: null,
    })
    renderTab()

    expect(screen.getByRole('button', { name: 'pagination.previous' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'pagination.next' })).toBeDisabled()
  })
})
