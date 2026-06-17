import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InventorySettings } from './InventorySettings'

// ─── i18n mock ──────────────────────────────────────────────────────────────
// Echo the key (or the interpolation string) so assertions can target keys.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── sonner mock ────────────────────────────────────────────────────────────
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ─── Stores mocks (selector form + getState for tenantScopedKey) ────────────
const authState = { user: { tenant_id: 'tenant-1' } }
const companyState = { currentCompanyId: 'company-1' }
vi.mock('../../../stores/authStore', () => ({
  useAuthStore: Object.assign(
    (sel: (s: typeof authState) => unknown) => sel(authState),
    { getState: () => authState }
  ),
}))
vi.mock('../../../stores/companyStore', () => ({
  useCompanyStore: Object.assign(
    (sel: (s: typeof companyState) => unknown) => sel(companyState),
    { getState: () => companyState }
  ),
}))

// ─── useCompany hook mock ───────────────────────────────────────────────────
vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: { id: 'company-1', currency: 'EUR' },
  }),
}))

// ─── Fixtures ───────────────────────────────────────────────────────────────
const companyFixture = {
  id: 'company-1',
  name: 'Acme',
  default_target_margin: '30.00',
  default_minimum_margin: '15.00',
  allow_below_cost_sales: false,
}

const reservationFixture = {
  sales_order_expiry_days: 30,
  ecommerce_cart_expiry_minutes: 30,
  marketplace_order_expiry_hours: 24,
  customer_return_expiry_days: 14,
  high_value_alert_threshold: '10000.00',
  inventory_count_trigger_threshold: '5000.00',
  auto_reserve_on_sales_order: true,
}

// ─── TanStack Query mock ────────────────────────────────────────────────────
// First useQuery call → company-settings; second → reservation-settings.
vi.mock('@tanstack/react-query', () => {
  let call = 0
  return {
    useQuery: () => {
      call += 1
      if (call % 2 === 1) {
        return { data: { data: companyFixture }, isLoading: false }
      }
      return { data: reservationFixture, isLoading: false, isLoadingReservation: false }
    },
    useMutation: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('InventorySettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders margin percent fields as number inputs via atoms', () => {
    render(<InventorySettings />)
    const target = screen.getByLabelText('inventory:settings.margins.targetLabel') as HTMLInputElement
    const minimum = screen.getByLabelText('inventory:settings.margins.minimumLabel') as HTMLInputElement
    expect(target.tagName).toBe('INPUT')
    expect(target.type).toBe('number')
    expect(target.value).toBe('30.00')
    expect(minimum.value).toBe('15.00')
  })

  it('renders reservation expiry fields as number inputs', () => {
    render(<InventorySettings />)
    const salesOrder = screen.getByLabelText(
      'inventory:settings.reservations.expiry.salesOrder.label'
    ) as HTMLInputElement
    expect(salesOrder.type).toBe('number')
    expect(salesOrder.value).toBe('30')
  })

  it('renders money threshold fields seeded from fixture', () => {
    render(<InventorySettings />)
    // Label is interpolated with currency suffix, so query by display value.
    expect(screen.getByDisplayValue('10000.00')).toBeInTheDocument()
    expect(screen.getByDisplayValue('5000.00')).toBeInTheDocument()
  })

  it('renders the save action as a <button>', () => {
    render(<InventorySettings />)
    const saveButton = screen.getByRole('button')
    expect(saveButton.tagName).toBe('BUTTON')
  })
})
