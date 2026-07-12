import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAdjustRepositoryBalance } from '../useAdjustRepositoryBalance'

const mocks = vi.hoisted(() => ({
  invalidateQueries: vi.fn(),
  post: vi.fn(),
  toastError: vi.fn(),
  getErrorMessage: vi.fn(),
}))

vi.mock('@tanstack/react-query', () => ({
  useQueryClient: () => ({ invalidateQueries: mocks.invalidateQueries }),
  useMutation: (options: {
    mutationFn: (variables: unknown) => Promise<unknown>
    onSuccess?: (data: unknown, variables: unknown) => Promise<void> | void
    onError?: (error: unknown) => void
  }) => ({
    mutateAsync: async (variables: unknown) => {
      try {
        const data = await options.mutationFn(variables)
        await options.onSuccess?.(data, variables)
        return data
      } catch (error) {
        options.onError?.(error)
        throw error
      }
    },
    isPending: false,
  }),
}))

vi.mock('@/lib/api', () => ({
  api: { post: mocks.post },
  getErrorMessage: mocks.getErrorMessage,
}))

vi.mock('@/lib/tenantScopedKey', () => ({
  tenantScopedKey: (segments: readonly unknown[]) => [...segments, 'tenant-1', 'company-1'],
}))

vi.mock('sonner', () => ({ toast: { error: mocks.toastError } }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('useAdjustRepositoryBalance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.post.mockResolvedValue({
      data: {
        data: {
          movement_id: 'movement-1',
          balance_after: '101.250',
          ordinal: 4,
          idempotent_replay: false,
        },
      },
    })
  })

  it('posts the exact decimal-string contract and invalidates every tenant-scoped balance view', async () => {
    const { result } = renderHook(() => useAdjustRepositoryBalance('repo-1'))
    const payload = {
      direction: 'in' as const,
      amount: '1.250',
      reason_code: 'count_variance' as const,
      reason_text: 'Till count overage',
    }

    await act(async () => {
      await result.current.mutateAsync(payload)
    })

    expect(mocks.post).toHaveBeenCalledWith('/payment-repositories/repo-1/adjustments', payload)
    expect(mocks.invalidateQueries).toHaveBeenCalledTimes(4)
    expect(mocks.invalidateQueries).toHaveBeenCalledWith({
      queryKey: ['payment-repository', 'repo-1', 'tenant-1', 'company-1'],
    })
    expect(mocks.invalidateQueries).toHaveBeenCalledWith({
      queryKey: ['payment-repository-transactions', 'repo-1', 'tenant-1', 'company-1'],
    })
    const movementsInvalidation = mocks.invalidateQueries.mock.calls
      .map(([options]) => options as { predicate?: (query: { queryKey: readonly unknown[] }) => boolean })
      .find((options) => typeof options.predicate === 'function')
    expect(movementsInvalidation?.predicate?.({
      queryKey: ['repository-movements', 'repo-1', { page: 2 }, 'tenant-1', 'company-1'],
    })).toBe(true)
    expect(movementsInvalidation?.predicate?.({
      queryKey: ['repository-movements', 'repo-1', { page: 2 }, 'tenant-2', 'company-1'],
    })).toBe(false)
    expect(mocks.invalidateQueries).toHaveBeenCalledWith({
      queryKey: ['treasury-cash-position', 'tenant-1', 'company-1'],
    })
  })

  it('shows a flat-string 422 error verbatim before consulting the generic extractor', async () => {
    const error = { response: { data: { error: 'Tolerance account 758 is missing' } } }
    mocks.post.mockRejectedValue(error)
    const { result } = renderHook(() => useAdjustRepositoryBalance('repo-1'))

    await expect(result.current.mutateAsync({
      direction: 'out',
      amount: '2.000',
      reason_code: 'correction',
      reason_text: 'Correction',
    })).rejects.toBe(error)

    expect(mocks.toastError).toHaveBeenCalledWith('Tolerance account 758 is missing')
    expect(mocks.getErrorMessage).not.toHaveBeenCalled()
  })

  it('falls back to the canonical extractor and then the translated generic message', async () => {
    const error = { response: { data: { error: { code: 'BUSINESS_ERROR', message: 'No GL account' } } } }
    mocks.post.mockRejectedValue(error)
    mocks.getErrorMessage.mockReturnValueOnce('No GL account')
    const { result } = renderHook(() => useAdjustRepositoryBalance('repo-1'))

    await expect(result.current.mutateAsync({
      direction: 'in',
      amount: '2.000',
      reason_code: 'other',
      reason_text: 'Other',
    })).rejects.toBe(error)
    expect(mocks.toastError).toHaveBeenLastCalledWith('No GL account')

    mocks.getErrorMessage.mockReturnValueOnce('')
    await expect(result.current.mutateAsync({
      direction: 'in',
      amount: '2.000',
      reason_code: 'other',
      reason_text: 'Other',
    })).rejects.toBe(error)
    expect(mocks.toastError).toHaveBeenLastCalledWith('treasury:repositories.adjustBalance.error')
  })
})
