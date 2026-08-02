import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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

// ─── usePermissions mock ────────────────────────────────────────────────────
const mockHasPermission = vi.hoisted(() => vi.fn(() => true))
vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
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
const mockMutateAsync = vi.hoisted(() => vi.fn(() => Promise.resolve(undefined)))
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
    useMutation: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('InventorySettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission.mockReturnValue(true)
    mockMutateAsync.mockClear()
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

  it('allows reservation expiry fields to be empty while typing and restores per-field minimums on blur', async () => {
    const user = userEvent.setup()
    render(<InventorySettings />)

    const salesOrder = screen.getByLabelText(
      'inventory:settings.reservations.expiry.salesOrder.label'
    ) as HTMLInputElement
    const cart = screen.getByLabelText(
      'inventory:settings.reservations.expiry.cart.label'
    ) as HTMLInputElement

    await user.clear(salesOrder)
    expect(salesOrder.value).toBe('')

    await user.type(salesOrder, '0')
    expect(salesOrder.value).toBe('0')

    await user.tab()
    expect(salesOrder.value).toBe('1')

    await user.clear(cart)
    expect(cart.value).toBe('')

    await user.type(cart, '0')
    expect(cart.value).toBe('0')

    await user.tab()
    expect(cart.value).toBe('5')
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

  // M1/M2/M3 (gate review docs/superpowers/reviews/2026-08-02-fe-batch-gate.md): the F1
  // settings.update gate on this screen (aa3fc1cc4) had zero test coverage — mutation-testing
  // it (deleting `|| !canEdit`) left the whole suite green. This asserts the real behaviour.
  it('disables Save and shows the read-only hint for a caller without settings.update; the mutation never fires on click', async () => {
    mockHasPermission.mockReturnValue(false)
    const user = userEvent.setup()
    render(<InventorySettings />)

    const target = screen.getByLabelText('inventory:settings.margins.targetLabel') as HTMLInputElement
    await user.clear(target)
    await user.type(target, '35.00')

    const saveButton = screen.getByRole('button')
    expect(saveButton).toBeDisabled()
    expect(screen.getByText('common:permissions.readOnlyEditHint')).toBeInTheDocument()

    await user.click(saveButton)
    expect(mockMutateAsync).not.toHaveBeenCalled()
  })
})
