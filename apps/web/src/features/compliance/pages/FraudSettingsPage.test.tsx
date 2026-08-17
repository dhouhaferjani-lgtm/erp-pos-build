import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { I18nextProvider } from 'react-i18next'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '@/lib/i18n'
import { FraudSettingsPage } from './FraudSettingsPage'

const apiMocks = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  reset: vi.fn(),
}))

vi.mock('../api/fraudApi', () => ({
  getFraudSettings: apiMocks.get,
  updateFraudSettings: apiMocks.update,
  resetFraudSettings: apiMocks.reset,
}))

vi.mock('@/stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (value: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

vi.mock('@/stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (value: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))

describe('FraudSettingsPage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    vi.clearAllMocks()
    apiMocks.get.mockResolvedValue({
      data: {
        id: null,
        company_id: 'company-1',
        abandoned_draft_threshold: 7,
        time_window_days: 30,
        alert_emails: [],
        alert_enabled: true,
        auto_trigger_counting: true,
        auto_restrict_access: false,
        cash_variance_over_soft: '1.0000',
        cash_variance_over_hard: '20.0000',
        cash_variance_under_soft: '1.0000',
        cash_variance_under_hard: '20.0000',
        require_blind_cash_count: undefined,
        require_manager_pin_above_hard: true,
        cash_variance_email_severity: 'none',
        created_at: null,
        updated_at: null,
        is_configured: false,
      },
    })
    apiMocks.update.mockResolvedValue({ data: {}, message: 'ok' })
  })

  it('does not re-disable blind counting when a row-less response omits the field', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    render(
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <FraudSettingsPage />
        </I18nextProvider>
      </QueryClientProvider>,
    )

    await waitFor(() => {
      expect(screen.getByLabelText('Abandoned Draft Threshold')).toHaveValue(7)
    })

    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(apiMocks.update).toHaveBeenCalledWith(
        expect.objectContaining({ require_blind_cash_count: true }),
        expect.anything(),
      )
    })
    await waitFor(() => {
      expect(apiMocks.get).toHaveBeenCalledTimes(2)
    })
  })

  it('renders the blind-count label through the Arabic compliance namespace', async () => {
    await i18n.changeLanguage('ar')
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <FraudSettingsPage />
        </I18nextProvider>
      </QueryClientProvider>,
    )

    await waitFor(() => {
      expect(screen.getByLabelText('Abandoned Draft Threshold')).toHaveValue(7)
    })
    expect(
      await screen.findByText('طلب جرد أعمى (لا يرى أمناء الصندوق المبلغ المتوقع)'),
    ).toBeInTheDocument()
  })
})
