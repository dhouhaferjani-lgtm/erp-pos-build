import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { PosRefundPoliciesPage } from '../PosRefundPoliciesPage'
import { POS_REFUND_POLICY_DEFAULTS } from '../../types/posRefundPolicies'
import type { PosRefundPolicies } from '../../types/posRefundPolicies'

// ─── i18n mock ────────────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Router mock ──────────────────────────────────────────────────────────────
vi.mock('react-router-dom', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => (
    <a href={to}>{children}</a>
  ),
}))

// ─── Toast mock ───────────────────────────────────────────────────────────────
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ─── useCompany mock ──────────────────────────────────────────────────────────
vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'company-uuid-1', name: 'Test Co' } }),
}))

// ─── usePosRefundPolicies mock (the GET hook) ─────────────────────────────────
const mockQueryReturn: {
  data: PosRefundPolicies | undefined
  isLoading: boolean
} = {
  data: { ...POS_REFUND_POLICY_DEFAULTS },
  isLoading: false,
}

const mockMutate = vi.fn()
const mockMutationReturn = {
  mutate: mockMutate,
  isPending: false,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockQueryReturn,
    useMutation: () => mockMutationReturn,
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

// ─── usePermissions mock ──────────────────────────────────────────────────────
const mockCanAccessModule = vi.fn(() => true)

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    canAccessModule: mockCanAccessModule,
    hasPermission: vi.fn(() => true),
    hasAnyPermission: vi.fn(() => true),
    hasAllPermissions: vi.fn(() => true),
  }),
}))

// ─── Helpers ──────────────────────────────────────────────────────────────────

function renderPage() {
  return render(<PosRefundPoliciesPage />)
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('PosRefundPoliciesPage', () => {
  beforeEach(() => {
    mockQueryReturn.data = { ...POS_REFUND_POLICY_DEFAULTS }
    mockQueryReturn.isLoading = false
    mockMutate.mockClear()
    mockCanAccessModule.mockReturnValue(true)
  })

  it('renders page title', () => {
    renderPage()
    expect(screen.getByText('refund-policies:pageTitle')).toBeInTheDocument()
  })

  it('renders with loaded settings populated into form fields', () => {
    renderPage()
    // customer_return_expiry_days default is 30
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    expect(input.value).toBe('30')
  })

  it('shows loading state during GET', () => {
    mockQueryReturn.isLoading = true
    mockQueryReturn.data = undefined
    renderPage()
    expect(screen.getByTestId('loading-spinner')).toBeInTheDocument()
  })

  it('Save button is disabled when form is not dirty', () => {
    renderPage()
    const saveBtn = screen.getByTestId('save-button')
    expect(saveBtn).toBeDisabled()
  })

  it('Save button becomes enabled after a field change', () => {
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '45' } })
    const saveBtn = screen.getByTestId('save-button')
    expect(saveBtn).not.toBeDisabled()
  })

  it('calls update mutation with the changed payload on submit', async () => {
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '45' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.customer_return_expiry_days).toBe(45)
  })

  it('validation: customer_return_expiry_days below 0 prevents submit', async () => {
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '-1' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).not.toHaveBeenCalled()
    })
  })

  it('validation: manager_override_threshold_percent above 100 prevents submit', async () => {
    renderPage()
    const input = screen.getByTestId(
      'field-manager_override_threshold_percent'
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: '101' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).not.toHaveBeenCalled()
    })
  })

  it('Reset to defaults fills form with default values', async () => {
    renderPage()
    // First change a value
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '60' } })
    // Then reset
    fireEvent.click(screen.getByTestId('reset-button'))
    await waitFor(() => {
      expect(input.value).toBe('30')
    })
    // Should not have auto-saved
    expect(mockMutate).not.toHaveBeenCalled()
  })

  it('unchecking cash destination reflects in form state and submit payload', async () => {
    renderPage()
    const cashCheckbox = screen.getByTestId('destination-cash') as HTMLInputElement
    expect(cashCheckbox.checked).toBe(true)
    fireEvent.click(cashCheckbox)
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.allowed_refund_destinations).not.toContain('cash')
  })

  it('daily_refund_cap_per_cashier: enabling cap shows amount input', async () => {
    renderPage()
    // Initially cap input should not be visible (null default)
    expect(screen.queryByTestId('field-daily_refund_cap_per_cashier')).not.toBeInTheDocument()
    // Enable cap
    const enableCapToggle = screen.getByTestId('daily-cap-cashier-enable') as HTMLInputElement
    fireEvent.click(enableCapToggle)
    // Input should now appear
    const capInput = screen.getByTestId('field-daily_refund_cap_per_cashier') as HTMLInputElement
    // Enter a value and submit — payload should contain the cap
    fireEvent.change(capInput, { target: { value: '500.00' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.daily_refund_cap_per_cashier).toBe('500.00')
  })

  it('goodwill_daily_issuance_cap_per_user: enabling cap allows entering a value', async () => {
    renderPage()
    const enableToggle = screen.getByTestId(
      'goodwill-daily-cap-enable'
    ) as HTMLInputElement
    fireEvent.click(enableToggle)
    const capInput = screen.getByTestId(
      'field-goodwill_daily_issuance_cap_per_user'
    ) as HTMLInputElement
    fireEvent.change(capInput, { target: { value: '300.00' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.goodwill_daily_issuance_cap_per_user).toBe('300.00')
  })

  it('renders Access Denied state for non-settings users', () => {
    mockCanAccessModule.mockReturnValue(false)
    renderPage()
    expect(screen.getByTestId('access-denied')).toBeInTheDocument()
    expect(screen.queryByTestId('save-button')).not.toBeInTheDocument()
  })
})
