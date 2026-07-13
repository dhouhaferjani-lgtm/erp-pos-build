import { QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  recurrenceKeys,
  useDeleteExpenseRecurrence,
  useExpenseRecurrences,
  usePauseExpenseRecurrence,
  useResumeExpenseRecurrence,
} from './useExpenseRecurrences'
import {
  expenseRecurrenceDetailInvalidationPredicate,
  expenseRecurrencesInvalidationPredicate,
} from '../_invalidation'

const mockList = vi.hoisted(() => vi.fn())
const mockDelete = vi.hoisted(() => vi.fn())
const mockPause = vi.hoisted(() => vi.fn())
const mockResume = vi.hoisted(() => vi.fn())
const mockToastError = vi.hoisted(() => vi.fn())

vi.mock('../api/recurrenceApi', () => ({
  recurrenceApi: {
    delete: mockDelete,
    list: mockList,
    pause: mockPause,
    resume: mockResume,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: mockToastError },
}))

function wrapper(client: ReturnType<typeof createTestQueryClient>) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function setScope() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Operator',
      email: 'operator@example.test',
      tenant_id: 'tenant-a',
      roles: ['accountant'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-a',
    companies: [],
    isLoading: false,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setScope()
  mockList.mockResolvedValue([])
  mockDelete.mockResolvedValue(undefined)
  mockPause.mockResolvedValue({ id: 'rec-1', status: 'paused' })
  mockResume.mockResolvedValue({ id: 'rec-1', status: 'active' })
})

describe('expense recurrence query scope and mutation cascades', () => {
  it('stamps the recurrence list query with tenant and company', async () => {
    const client = createTestQueryClient()
    const { result } = renderHook(() => useExpenseRecurrences(), {
      wrapper: wrapper(client),
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(recurrenceKeys.list()).toEqual(['expense-recurrences', 'list'])
    expect(client.getQueryCache().getAll().map((query) => query.queryKey)).toContainEqual([
      'expense-recurrences',
      'list',
      'tenant-a',
      'company-a',
    ])
  })

  it.each([
    ['pause', usePauseExpenseRecurrence, mockPause],
    ['resume', useResumeExpenseRecurrence, mockResume],
  ] as const)('%s refreshes only the scoped recurrence list and the forecast prefix', async (_label, useMutationHook, apiMutation) => {
    const client = createTestQueryClient()
    const invalidate = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => {
      const mutation = useMutationHook()
      const queryClient = useQueryClient()
      return { mutation, queryClient }
    }, { wrapper: wrapper(client) })

    await result.current.mutation.mutateAsync('rec-1')

    expect(apiMutation).toHaveBeenCalledWith('rec-1')
    expect(invalidate.mock.calls.some(([filters]) => typeof filters?.predicate === 'function')).toBe(true)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['upcoming-payments'] })

    const predicate = expenseRecurrencesInvalidationPredicate('tenant-a', 'company-a')
    expect(predicate({
      queryKey: ['expense-recurrences', 'list', 'tenant-a', 'company-a'],
    })).toBe(true)
    expect(predicate({
      queryKey: ['expense-recurrences', 'list', 'tenant-b', 'company-a'],
    })).toBe(false)
  })

  it('matches a recurrence detail by id and active scope without suffix-prefix filtering', () => {
    const predicate = expenseRecurrenceDetailInvalidationPredicate(
      'rec-1',
      'tenant-a',
      'company-a',
    )

    expect(predicate({
      queryKey: ['expense-recurrences', 'detail', 'rec-1', 'tenant-a', 'company-a'],
    })).toBe(true)
    expect(predicate({
      queryKey: ['expense-recurrences', 'detail', 'rec-2', 'tenant-a', 'company-a'],
    })).toBe(false)
    expect(predicate({
      queryKey: ['expense-recurrences', 'detail', 'rec-1', 'tenant-b', 'company-a'],
    })).toBe(false)
  })

  it('delete invalidates the exact recurrence detail in the active scope', async () => {
    const client = createTestQueryClient()
    const deletedKey = ['expense-recurrences', 'detail', 'rec-1', 'tenant-a', 'company-a'] as const
    const otherIdKey = ['expense-recurrences', 'detail', 'rec-2', 'tenant-a', 'company-a'] as const
    const otherTenantKey = ['expense-recurrences', 'detail', 'rec-1', 'tenant-b', 'company-a'] as const
    client.setQueryData(deletedKey, {})
    client.setQueryData(otherIdKey, {})
    client.setQueryData(otherTenantKey, {})
    const deletedQuery = client.getQueryCache().find({ queryKey: deletedKey, exact: true })
    const otherIdQuery = client.getQueryCache().find({ queryKey: otherIdKey, exact: true })
    const otherTenantQuery = client.getQueryCache().find({ queryKey: otherTenantKey, exact: true })
    if (!deletedQuery || !otherIdQuery || !otherTenantQuery) {
      throw new Error('Expected recurrence detail queries to be seeded')
    }
    const invalidate = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useDeleteExpenseRecurrence(), {
      wrapper: wrapper(client),
    })

    await result.current.mutateAsync('rec-1')

    expect(mockDelete).toHaveBeenCalledWith('rec-1')
    const predicates = invalidate.mock.calls
      .map(([filters]) => filters?.predicate)
      .filter((predicate): predicate is NonNullable<typeof predicate> => (
        typeof predicate === 'function'
      ))

    expect(predicates.some((predicate) => predicate(deletedQuery))).toBe(true)
    expect(predicates.some((predicate) => predicate(otherIdQuery))).toBe(false)
    expect(predicates.some((predicate) => predicate(otherTenantQuery))).toBe(false)
  })

  it('keeps the hook error toast when a lifecycle mutation rejects', async () => {
    mockPause.mockRejectedValueOnce(new Error('pause failed'))
    const client = createTestQueryClient()
    const { result } = renderHook(() => usePauseExpenseRecurrence(), {
      wrapper: wrapper(client),
    })

    await expect(result.current.mutateAsync('rec-1')).rejects.toThrow('pause failed')

    expect(mockToastError).toHaveBeenCalledWith('recurrences.messages.error')
  })
})
