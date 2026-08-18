import { act, renderHook } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { usePayExpense } from './useExpenses'

const mocks = vi.hoisted(() => ({
  pay: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  getErrorMessage: vi.fn(),
}))

vi.mock('../api/expenseApi', () => ({
  expenseApi: { pay: mocks.pay },
}))

vi.mock('@/lib/api', () => ({ getErrorMessage: mocks.getErrorMessage }))

vi.mock('sonner', () => ({
  toast: { error: mocks.toastError, success: mocks.toastSuccess },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { tenant_id: string } }) => unknown) =>
    selector({ user: { tenant_id: 'tenant-1' } }),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: (selector: (state: { currentCompanyId: string }) => unknown) =>
    selector({ currentCompanyId: 'company-1' }),
}))

// Promoted L3 locationScopedKey lane: mutation tests pin the all-locations view scope.
vi.mock('@/features/locations/hooks/useViewScope', () => ({
  useViewScope: () => ({ scope: 'all', effectiveLocationIds: [], isAll: true, setScope: vi.fn() }),
}))

function wrapperWith(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

describe('usePayExpense', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.pay.mockResolvedValue({ id: 'expense-1' })
  })

  it('pays with the exact no-amount contract and invalidates expense and cash views', async () => {
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
    const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue()
    const { result } = renderHook(() => usePayExpense(), { wrapper: wrapperWith(client) })
    const data = {
      payment_repository_id: 'repo-1',
      payment_method_id: 'method-1',
      payment_date: '2026-07-12',
    }

    await act(async () => {
      await result.current.mutateAsync({ id: 'expense-1', data })
    })

    expect(mocks.pay).toHaveBeenCalledWith('expense-1', data)
    expect(mocks.pay.mock.calls[0]?.[1]).not.toHaveProperty('amount')
    expect(invalidate).toHaveBeenCalledWith({ predicate: expect.any(Function) })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['expenses', 'detail', 'expense-1'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['payment-repository', 'repo-1'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['treasury-cash-position'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['instruments'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['maturing-instruments'] })
    expect(mocks.toastSuccess).toHaveBeenCalledWith('expenses:pay.success')
  })

  it('renders the flat-string already-paid 422 verbatim', async () => {
    const error = { response: { data: { error: 'This expense has already been paid' } } }
    mocks.pay.mockRejectedValue(error)
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
    const { result } = renderHook(() => usePayExpense(), { wrapper: wrapperWith(client) })

    await expect(result.current.mutateAsync({
      id: 'expense-1',
      data: {
        payment_repository_id: 'repo-1',
        payment_method_id: null,
        payment_date: '2026-07-12',
      },
    })).rejects.toBe(error)

    expect(mocks.toastError).toHaveBeenCalledWith('This expense has already been paid')
    expect(mocks.getErrorMessage).not.toHaveBeenCalled()
  })

  it('falls back through the canonical extractor to the translated message', async () => {
    const error = { response: { data: { error: { message: 'Canonical failure' } } } }
    mocks.pay.mockRejectedValue(error)
    mocks.getErrorMessage.mockReturnValueOnce('Canonical failure').mockReturnValueOnce('')
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
    const { result } = renderHook(() => usePayExpense(), { wrapper: wrapperWith(client) })
    const variables = {
      id: 'expense-1',
      data: { payment_repository_id: 'repo-1', payment_date: '2026-07-12' },
    }

    await expect(result.current.mutateAsync(variables)).rejects.toBe(error)
    expect(mocks.toastError).toHaveBeenLastCalledWith('Canonical failure')
    await expect(result.current.mutateAsync(variables)).rejects.toBe(error)
    expect(mocks.toastError).toHaveBeenLastCalledWith('expenses:pay.error')
  })
})
