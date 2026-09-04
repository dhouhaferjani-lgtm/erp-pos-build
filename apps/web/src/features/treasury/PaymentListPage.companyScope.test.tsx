import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { resetAuth, seedAuth } from '../../test/seedAuth'
import { PaymentListPage, type Payment } from './PaymentListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ to, children, ...props }: { to: string; children: ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

const apiGet = vi.hoisted(() => vi.fn<(url: string) => Promise<PaymentsBody>>())
vi.mock('../../lib/api', () => ({
  api: { get: (url: string): Promise<PaymentsBody> => apiGet(url) },
}))

interface PaymentsBody {
  data: {
    data: Payment[]
    meta: {
      current_page: number
      last_page: number
      per_page: number
      total: number
      from: number | null
      to: number | null
    }
  }
}

function makePayment(overrides: Partial<Payment>): Payment {
  return {
    id: 'payment-0',
    payment_number: 'PAY-0',
    amount: 10,
    payment_date: '2026-09-01',
    payment_method_id: 'method-1',
    payment_method_name: 'Cash',
    partner_id: 'partner-1',
    partner_name: 'Alice',
    partner_type: 'customer',
    payment_type: 'inbound',
    status: 'completed',
    dishonored_at: null,
    created_at: '2026-09-01T10:00:00Z',
    ...overrides,
  }
}

function body(rows: Payment[], page: number, lastPage: number): PaymentsBody {
  return {
    data: {
      data: rows,
      meta: {
        current_page: page,
        last_page: lastPage,
        per_page: 25,
        total: rows.length,
        from: rows.length === 0 ? null : 1,
        to: rows.length === 0 ? null : rows.length,
      },
    },
  }
}

/** A request that stays in flight for the whole assertion window. */
function neverSettles(): Promise<PaymentsBody> {
  return new Promise<PaymentsBody>(() => { /* intentionally pending */ })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <PaymentListPage />
    </QueryClientProvider>,
  )
}

const companyOnePayment = makePayment({ id: 'p-c1', payment_number: 'PAY-COMPANY-ONE' })

describe('PaymentListPage scope-change placeholder', () => {
  beforeEach(() => {
    apiGet.mockReset()
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
  })

  afterEach(() => {
    // Unmount BEFORE clearing the stores: vitest runs this hook ahead of RTL's
    // auto-cleanup, so a bare `resetAuth()` would push a store update into a
    // still-mounted tree outside `act`.
    cleanup()
    resetAuth()
  })

  it('renders no company-one row while company two is still loading', async () => {
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOnePayment], 1, 1)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('PAY-COMPANY-ONE')

    act(() => {
      useCompanyStore.setState({ currentCompanyId: 'company-2' })
    })

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    expect(screen.queryByText('PAY-COMPANY-ONE')).not.toBeInTheDocument()
  })

  it('renders no previous-tenant row while the new tenant is still loading', async () => {
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOnePayment], 1, 1)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('PAY-COMPANY-ONE')

    act(() => {
      const user = useAuthStore.getState().user
      if (user === null) throw new Error('expected a seeded user')
      useAuthStore.setState({ user: { ...user, tenant_id: 'tenant-2' } })
    })

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    expect(screen.queryByText('PAY-COMPANY-ONE')).not.toBeInTheDocument()
  })

  it('keeps the previous page visible while the next page of the SAME company loads', async () => {
    const user = userEvent.setup()
    apiGet
      .mockImplementationOnce(() => Promise.resolve(body([companyOnePayment], 1, 2)))
      .mockImplementationOnce(neverSettles)

    renderPage()
    await screen.findByText('PAY-COMPANY-ONE')

    await user.click(screen.getByRole('button', { name: 'pagination.next' }))

    await waitFor(() => { expect(apiGet).toHaveBeenCalledTimes(2) })

    // The legitimate reason `placeholderData: keepPreviousData` is here at all:
    // paging inside one company must not flash an empty table.
    expect(screen.getByText('PAY-COMPANY-ONE')).toBeInTheDocument()
  })
})
