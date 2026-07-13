import { QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  recurrenceKeys,
  useExpenseRecurrences,
  usePauseExpenseRecurrence,
  useResumeExpenseRecurrence,
} from './useExpenseRecurrences'
import {
  expenseRecurrenceDetailInvalidationPredicate,
  expenseRecurrencesInvalidationPredicate,
} from '../_invalidation'

const mockList = vi.hoisted(() => vi.fn())
const mockPause = vi.hoisted(() => vi.fn())
const mockResume = vi.hoisted(() => vi.fn())

vi.mock('../api/recurrenceApi', () => ({
  recurrenceApi: {
    list: mockList,
    pause: mockPause,
    resume: mockResume,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
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
})
