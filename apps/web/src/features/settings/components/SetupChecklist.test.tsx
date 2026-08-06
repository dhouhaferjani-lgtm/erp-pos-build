import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SetupChecklist } from './SetupChecklist'
import type { OnboardingItem } from '../api/onboardingApi'

// ─── Hoisted mocks ────────────────────────────────────────────────────────────
const { mockFetchOnboardingStatus } = vi.hoisted(() => ({
  mockFetchOnboardingStatus: vi.fn(),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'onboarding.title': 'Setup Checklist',
        'onboarding.description': 'Complete these steps',
        'onboarding.loadErrorTitle': 'Could not load the setup checklist',
        'onboarding.loadErrorBody': 'Something went wrong on our side.',
        'common:actions.retry': 'Retry',
        'onboarding.steps.company_info': 'Company Information',
        'onboarding.badges.required': 'Required',
        'onboarding.badges.optional': 'Optional',
      }
      return map[key] ?? key
    },
  }),
}))

vi.mock('../../../stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (sel: (s: typeof state) => unknown) => sel(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

vi.mock('../../../stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (sel: (s: typeof state) => unknown) => sel(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

vi.mock('../api/onboardingApi', () => ({
  fetchOnboardingStatus: mockFetchOnboardingStatus,
}))

function renderChecklist() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SetupChecklist />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function makeItem(overrides: Partial<OnboardingItem> = {}): OnboardingItem {
  return {
    step: 'company_info',
    label: 'Company Information',
    completed: false,
    required: true,
    settings_path: '/settings/company',
    degraded: false,
    ...overrides,
  }
}

describe('SetupChecklist', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /**
   * BUG-005 / RCA B3 — when `/onboarding/status` 500s, the query fails and
   * `data` defaults to `[]`. The page then rendered a fully-successful-looking
   * checklist reading "0 of 0 completed" plus the "Setup complete!" banner —
   * telling the user their setup is done when in fact nothing loaded.
   */
  it('renders an error state instead of an empty 0-of-0 checklist when the request fails', async () => {
    mockFetchOnboardingStatus.mockRejectedValue(new Error('Server Error'))

    renderChecklist()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Could not load the setup checklist',
    )
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
    expect(screen.queryByText('0 of 0 completed')).not.toBeInTheDocument()
    expect(screen.queryByText(/Setup complete/i)).not.toBeInTheDocument()
  })

  it('renders the checklist when the request succeeds', async () => {
    mockFetchOnboardingStatus.mockResolvedValue([makeItem()])

    renderChecklist()

    await waitFor(() => {
      expect(screen.getByText('Company Information')).toBeInTheDocument()
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
