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
const mockHasPermission = vi.fn(() => true)

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    canAccessModule: mockCanAccessModule,
    hasPermission: mockHasPermission,
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
    mockHasPermission.mockReturnValue(true)
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

  // M1/M2/M3 (gate review docs/superpowers/reviews/2026-08-02-fe-batch-gate.md): the F1
  // settings.update gate on this screen (aa3fc1cc4) had zero real coverage — `hasPermission`
  // was hardcoded `true`, so the existing `toBeDisabled()` assertions above only ever
  // exercised `isDirty`, not the permission gate. This asserts the real behaviour.
  it('disables Save and shows the read-only hint for a caller without settings.update; the mutation never fires on click', () => {
    mockHasPermission.mockReturnValue(false)
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '45' } })

    const saveBtn = screen.getByTestId('save-button')
    expect(saveBtn).toBeDisabled()
    expect(screen.getByText('common:permissions.readOnlyEditHint')).toBeInTheDocument()

    fireEvent.click(saveBtn)
    expect(mockMutate).not.toHaveBeenCalled()
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

  // ── Fix 1 regression: form populates from loaded data, not defaults ──────────

  it('populates form with loaded settings, not defaults', () => {
    // Use a value that differs from the default (default is 30)
    mockQueryReturn.data = { ...POS_REFUND_POLICY_DEFAULTS, customer_return_expiry_days: 60 }
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    // Without the double-unwrap fix the form would load defaults (30) instead of 60
    expect(input.value).toBe('60')
  })

  // ── Fix 2: zero / blank cap is rejected on submit ────────────────────────────

  it('rejects daily_refund_cap_per_cashier=0 when enabled', async () => {
    renderPage()
    const enableCapToggle = screen.getByTestId('daily-cap-cashier-enable') as HTMLInputElement
    fireEvent.click(enableCapToggle)
    // Input appears with empty string — do NOT enter a value (simulates zero / blank)
    const capInput = screen.getByTestId('field-daily_refund_cap_per_cashier') as HTMLInputElement
    expect(capInput.value).toBe('')
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).not.toHaveBeenCalled()
    })
  })

  it('rejects goodwill_daily_issuance_cap_per_user=blank when enabled', async () => {
    renderPage()
    const enableToggle = screen.getByTestId('goodwill-daily-cap-enable') as HTMLInputElement
    fireEvent.click(enableToggle)
    const capInput = screen.getByTestId(
      'field-goodwill_daily_issuance_cap_per_user'
    ) as HTMLInputElement
    expect(capInput.value).toBe('')
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).not.toHaveBeenCalled()
    })
  })

  // ── Fix 4: acceptance-boundary tests ─────────────────────────────────────────

  it('accepts manager_override_threshold_percent = 100 (boundary)', async () => {
    renderPage()
    const input = screen.getByTestId(
      'field-manager_override_threshold_percent'
    ) as HTMLInputElement
    fireEvent.change(input, { target: { value: '100' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.manager_override_threshold_percent).toBe('100')
  })

  it('accepts customer_return_expiry_days = 90 (max boundary)', async () => {
    renderPage()
    const input = screen.getByTestId('field-customer_return_expiry_days') as HTMLInputElement
    fireEvent.change(input, { target: { value: '90' } })
    fireEvent.click(screen.getByTestId('save-button'))
    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledOnce()
    })
    const payload = mockMutate.mock.calls[0][0] as PosRefundPolicies
    expect(payload.customer_return_expiry_days).toBe(90)
  })
})
