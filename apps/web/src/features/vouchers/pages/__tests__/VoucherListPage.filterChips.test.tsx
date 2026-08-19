/**
 * VoucherListPage — source filter chip SELECTED-STATE contract (UI-12 / Wave 0 T5).
 *
 * Before the fix, `VoucherListPage.tsx:104-112` carried a ternary whose branches were
 * both the empty template literal, so the selected chip and the unselected chips were
 * byte-identical in the DOM and there was no accessible selection state at all.
 *
 * Per CLAUDE.md rule 17 these assertions are on rendered output and accessible state,
 * not on literal design-token class names: "the two class lists differ" plus the
 * `aria-pressed` attribute, so the state is not colour-only.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { VoucherListPage } from '../VoucherListPage'

// ─── Router mocks ──────────────────────────────────────────────────────────────

const mockNavigate = vi.fn()
const mockSetSearchParams = vi.fn()
let mockSearchParamsSource = ''

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useSearchParams: () => [
    { get: (key: string) => (key === 'source' ? mockSearchParamsSource : null) },
    mockSetSearchParams,
  ],
}))

// ─── i18n mock (returns the key literally) ────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

// ─── Query / permission mocks ─────────────────────────────────────────────────

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({
      data: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } },
      isLoading: false,
    }),
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
    hasAnyPermission: () => true,
    hasAllPermissions: () => true,
  }),
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { id: 'c1' } }),
}))

vi.mock('@/features/pos/hooks/useTerminals', () => ({
  useTerminals: () => ({ data: [] }),
}))

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: () => null,
}))

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('VoucherListPage — source filter chip selected state (UI-12)', () => {
  beforeEach(() => {
    mockSearchParamsSource = ''
    mockSetSearchParams.mockClear()
    mockNavigate.mockClear()
  })

  it('renders the selected chip visually distinct from an unselected chip', () => {
    mockSearchParamsSource = 'goodwill'
    render(<VoucherListPage />)

    const selected = screen.getByTestId('source-filter-goodwill')
    const unselected = screen.getByTestId('source-filter-refund')

    expect(selected.className).not.toBe(unselected.className)
  })

  it('exposes selection through aria-pressed, so it is not colour-only', () => {
    mockSearchParamsSource = 'goodwill'
    render(<VoucherListPage />)

    expect(screen.getByTestId('source-filter-goodwill')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('source-filter-refund')).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByTestId('source-filter-all')).toHaveAttribute('aria-pressed', 'false')
  })

  it('treats the "All" chip as selected when no source filter is applied', () => {
    mockSearchParamsSource = ''
    render(<VoucherListPage />)

    const all = screen.getByTestId('source-filter-all')
    const refund = screen.getByTestId('source-filter-refund')

    expect(all).toHaveAttribute('aria-pressed', 'true')
    expect(refund).toHaveAttribute('aria-pressed', 'false')
    expect(all.className).not.toBe(refund.className)
  })
})
