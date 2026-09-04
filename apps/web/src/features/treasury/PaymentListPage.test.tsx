import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PaymentListPage, type Payment } from './PaymentListPage'
import type { OffsetPaginationMeta } from '../../types/pagination'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// Stores: selector-aware mocks. authStore/companyStore are called with a
// selector; getCurrentCompany returns the current company directly.
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

interface PaymentsResponse {
  data: Payment[]
  meta: OffsetPaginationMeta
}

function makePayment(overrides: Partial<Payment>): Payment {
  return {
    id: 'id',
    payment_number: 'PAY-0000',
    amount: 0,
    payment_date: '2026-06-14',
    payment_method_id: 'm1',
    payment_method_name: 'Cash',
    partner_id: 'p1',
    partner_name: 'Alice Co',
    partner_type: 'customer',
    payment_type: null,
    status: 'pending',
    dishonored_at: null,
    created_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

const mockUseQueryReturn: {
  data: PaymentsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makePayment({ id: '1', payment_number: 'PAY-1001', status: 'pending', amount: 120.5 }),
      makePayment({ id: '2', payment_number: 'PAY-1002', status: 'completed', amount: 90 }),
      makePayment({ id: '3', payment_number: 'PAY-1003', status: 'failed', amount: 40 }),
      makePayment({ id: '4', payment_number: 'PAY-1004', status: 'reversed', amount: 60 }),
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 4, from: 1, to: 4 },
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockUseQueryReturn }
})

describe('PaymentListPage (canonical list)', () => {
  it('renders exactly one h1 page title', () => {
    render(<PaymentListPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a row per payment', () => {
    render(<PaymentListPage />)
    expect(screen.getByText('PAY-1001')).toBeInTheDocument()
    expect(screen.getByText('PAY-1002')).toBeInTheDocument()
  })

  it('renders status as a StatusBadge pill (rounded-full)', () => {
    render(<PaymentListPage />)
    // i18n mock returns the string default (2nd arg), so the label is the raw status.
    const badge = screen.getByText('pending')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('labels every backend PaymentStatus, including failed and reversed', () => {
    render(<PaymentListPage />)
    // The i18n mock echoes the string default, so each pill shows the raw
    // status key that `treasury:payments.statuses.<status>` resolves.
    for (const status of ['pending', 'completed', 'failed', 'reversed']) {
      const badge = screen.getByText(status)
      expect(badge.tagName).toBe('SPAN')
      expect(badge.className).toContain('rounded-full')
    }
  })

  it('navigates to the new-payment route from the Add button', async () => {
    const user = (await import('@testing-library/user-event')).default.setup()
    render(<PaymentListPage />)
    const addButton = screen.getByRole('button', { name: /treasury:payments\.record/ })
    await user.click(addButton)
    expect(mockNavigate).toHaveBeenCalledWith('/treasury/payments/new')
  })
})
