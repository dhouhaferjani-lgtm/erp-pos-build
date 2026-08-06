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

const { authState, companyState } = vi.hoisted(() => ({
  authState: { user: { tenant_id: 'tenant-1' } as { tenant_id: string } | null },
  companyState: { currentCompanyId: 'company-1' as string | null },
}))

vi.mock('../../../stores/authStore', () => {
  const useAuthStore = (sel: (s: typeof authState) => unknown) => sel(authState)
  useAuthStore.getState = () => authState
  return { useAuthStore }
})

vi.mock('../../../stores/companyStore', () => {
  const useCompanyStore = (sel: (s: typeof companyState) => unknown) => sel(companyState)
  useCompanyStore.getState = () => companyState
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
    authState.user = { tenant_id: 'tenant-1' }
    companyState.currentCompanyId = 'company-1'
  })

  /**
   * BUG-005 / RCA B3 — when `/onboarding/status` fails, the query errors and
   * `data` falls back to its `[]` default. The component then rendered the
   * ordinary checklist body: an empty item list and a progress bar reading
   * "0 of 0 completed", with no indication anything had gone wrong.
   *
   * (The "Setup complete!" banner is NOT part of this symptom — it was already
   * guarded by `totalCount > 0`.)
   *
   * The i18n mock returns the KEY for unmapped keys and ignores interpolation,
   * so the progress bar is asserted through `onboarding.progressLabel` — the
   * literal "0 of 0 completed" string can never appear under this mock and
   * asserting on it would be vacuous.
   */
  it('renders an error state instead of the empty checklist body when the request fails', async () => {
    mockFetchOnboardingStatus.mockRejectedValue(new Error('Server Error'))

    renderChecklist()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Could not load the setup checklist',
    )
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
    expect(screen.queryByText('onboarding.progressLabel')).not.toBeInTheDocument()
  })

  /**
   * MAJOR-1 (FE gate 2026-08-06) — `isLoading` is `isPending && isFetching`.
   * While the query is DISABLED (company store not yet hydrated, or a principal
   * with no company) it is pending + idle, so `isLoading` is false and
   * `isError` is false — the component fell straight through to the empty
   * checklist body and rendered the very "0 of 0" state the error branch was
   * added to eliminate. Gate on `isPending` instead.
   */
  it('shows the loading state instead of an empty checklist while the query is disabled', () => {
    companyState.currentCompanyId = null

    const { container } = renderChecklist()

    expect(mockFetchOnboardingStatus).not.toHaveBeenCalled()
    expect(screen.queryByText('onboarding.progressLabel')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(container.querySelector('[data-testid="spinner"], svg')).not.toBeNull()
  })

  it('renders the checklist when the request succeeds', async () => {
    mockFetchOnboardingStatus.mockResolvedValue([makeItem()])

    renderChecklist()

    await waitFor(() => {
      expect(screen.getByText('Company Information')).toBeInTheDocument()
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByText('onboarding.progressLabel')).toBeInTheDocument()
  })

  /**
   * MAJOR-2 (FE gate 2026-08-06) — a step whose backend probe threw is reported
   * `completed: false, degraded: true`. Rendering it identically to a genuinely
   * incomplete required step sends the user to fix something that may be fine —
   * the same silent-partial-failure class B1/B3 just closed, one layer down.
   */
  it('renders a degraded step distinctly from an incomplete required step', async () => {
    mockFetchOnboardingStatus.mockResolvedValue([
      makeItem({ step: 'payment_methods', degraded: true, required: true }),
      makeItem({ step: 'company_info', degraded: false, required: true }),
    ])

    renderChecklist()

    await waitFor(() => {
      expect(screen.getByText('onboarding.badges.unavailable')).toBeInTheDocument()
    })

    // Exactly one row is flagged unavailable; the healthy required row keeps
    // the ordinary Required badge.
    expect(screen.getAllByText('onboarding.badges.unavailable')).toHaveLength(1)
    expect(screen.getAllByText('Required')).toHaveLength(1)
  })

  it('does not count a degraded step as completed', async () => {
    mockFetchOnboardingStatus.mockResolvedValue([
      makeItem({ step: 'payment_methods', degraded: true, completed: false }),
    ])

    renderChecklist()

    await waitFor(() => {
      expect(screen.getByText('onboarding.badges.unavailable')).toBeInTheDocument()
    })

    // allDone must not appear while a required step is unresolved.
    expect(screen.queryByText('onboarding.allDone')).not.toBeInTheDocument()
  })
})
