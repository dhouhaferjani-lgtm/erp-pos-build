import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

import { IncomeListPage } from './IncomeListPage'

// ─── i18n: echo key, or return the string-interpolation arg when present ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── react-router-dom: capture navigate, render Link as a plain anchor ────────
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

// ─── RequirePermission: passthrough (permission plumbing is not under test) ───
vi.mock('@/components/auth/RequirePermission', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

// ─── income hooks → tiny fixtures (presentation-only isolation) ───────────────
const mockUseIncomeList = vi.fn()
const mockPostMutate = vi.fn()
vi.mock('../hooks/useIncome', () => ({
  useIncomeList: () => mockUseIncomeList() as unknown,
  usePostIncome: () => ({ mutate: mockPostMutate, isPending: false }),
}))

const mockRefetch = vi.fn()

beforeEach(() => {
  mockNavigate.mockReset()
  mockRefetch.mockReset()
  mockUseIncomeList.mockReset()
  mockUseIncomeList.mockReturnValue({
    data: { data: [] },
    isLoading: false,
    isError: false,
    error: null,
    refetch: mockRefetch,
  })
})

describe('IncomeListPage', () => {
  it('renders the empty state when the query succeeds with no rows', () => {
    render(<IncomeListPage />)
    expect(screen.getByText('income:noIncome')).toBeInTheDocument()
  })

  it('renders the loading state while the query is loading', () => {
    mockUseIncomeList.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      error: null,
      refetch: mockRefetch,
    })
    render(<IncomeListPage />)
    expect(screen.getByText('common:loading')).toBeInTheDocument()
  })

  describe('error state (TD-013 fix round)', () => {
    beforeEach(() => {
      mockUseIncomeList.mockReturnValue({
        data: undefined,
        isLoading: false,
        isError: true,
        error: new Error('boom'),
        refetch: mockRefetch,
      })
    })

    it('renders an error state — NOT the misleading "no income" empty state', () => {
      render(<IncomeListPage />)
      expect(screen.queryByText('income:noIncome')).not.toBeInTheDocument()
      // QueryError renders the extracted error message and a retry action.
      expect(screen.getByText('actions.tryAgain')).toBeInTheDocument()
    })

    it('retries the query via refetch when the retry action is clicked', () => {
      render(<IncomeListPage />)
      fireEvent.click(screen.getByText('actions.tryAgain'))
      expect(mockRefetch).toHaveBeenCalledTimes(1)
    })
  })
})
