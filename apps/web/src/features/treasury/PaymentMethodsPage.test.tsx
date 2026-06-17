import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PaymentMethodsPage } from './PaymentMethodsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// Stub the Add modal so the page test focuses on the list shell.
vi.mock('./components/AddPaymentMethodModal', () => ({
  AddPaymentMethodModal: () => null,
}))

interface PaymentMethodRow {
  id: string
  code: string
  name: string
  description: string | null
  is_physical: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  is_active: boolean
  fee_type: 'none' | 'fixed' | 'percentage' | 'mixed'
  fixed_fee_amount: string | null
  variable_fee_percentage: string | null
}

function makeMethod(overrides: Partial<PaymentMethodRow>): PaymentMethodRow {
  return {
    id: 'm1',
    code: 'CASH',
    name: 'Cash',
    description: null,
    is_physical: true,
    has_maturity: false,
    requires_third_party: false,
    is_push: true,
    has_deducted_fees: false,
    is_restricted: false,
    is_active: true,
    fee_type: 'none',
    fixed_fee_amount: null,
    variable_fee_percentage: null,
    ...overrides,
  }
}

const mockMethods: PaymentMethodRow[] = [
  makeMethod({ id: '1', code: 'CASH', name: 'Cash', is_active: true }),
  makeMethod({ id: '2', code: 'CARD', name: 'Card', is_active: false }),
]

vi.mock('./hooks/usePaymentMethods', () => ({
  usePaymentMethods: () => ({ data: mockMethods, isLoading: false, error: null }),
}))

vi.mock('../../lib/api', () => ({
  api: { patch: vi.fn() },
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
  }
})

describe('PaymentMethodsPage (canonical list)', () => {
  it('renders exactly one h1 page title', () => {
    render(<PaymentMethodsPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the Add control as a button', () => {
    render(<PaymentMethodsPage />)
    const addButton = screen.getByRole('button', { name: /treasury:paymentMethods\.new/ })
    expect(addButton.tagName).toBe('BUTTON')
  })

  it('renders a row per payment method', () => {
    render(<PaymentMethodsPage />)
    expect(screen.getByText('Cash')).toBeInTheDocument()
    expect(screen.getByText('Card')).toBeInTheDocument()
  })

  it('renders status as a StatusBadge pill (rounded-full)', () => {
    render(<PaymentMethodsPage />)
    // The inactive label only appears as the status pill (not as a stat-card heading).
    const badge = screen.getByText('treasury:paymentMethods.inactive')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })
})
