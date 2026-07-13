import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  expenseRecurrenceDetailInvalidationPredicate,
  expenseRecurrencesInvalidationPredicate,
} from '../_invalidation'
import { recurrenceApi } from '../api/recurrenceApi'
import type { CreateExpenseRecurrenceDTO } from '../types'

export const recurrenceKeys = {
  all: ['expense-recurrences'] as const,
  list: () => ['expense-recurrences', 'list'] as const,
  detail: (id: string) => ['expense-recurrences', 'detail', id] as const,
}

function useRecurrenceScope() {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  return { tenantId, companyId }
}

export function useExpenseRecurrences() {
  const { tenantId, companyId } = useRecurrenceScope()
  return useQuery({
    queryKey: tenantScopedKey([...recurrenceKeys.list()]),
    queryFn: () => recurrenceApi.list(),
    enabled: !!tenantId && !!companyId,
  })
}

export function useExpenseRecurrence(id: string) {
  const { tenantId, companyId } = useRecurrenceScope()
  return useQuery({
    queryKey: tenantScopedKey([...recurrenceKeys.detail(id)]),
    queryFn: () => recurrenceApi.get(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

function useRecurrenceInvalidation() {
  const queryClient = useQueryClient()
  const { tenantId, companyId } = useRecurrenceScope()

  return async (id?: string) => {
    const invalidations: Promise<unknown>[] = [
      queryClient.invalidateQueries({
        predicate: expenseRecurrencesInvalidationPredicate(tenantId, companyId),
      }),
      // Recurrence edits immediately change the projected money-out forecast.
      queryClient.invalidateQueries({ queryKey: ['upcoming-payments'] }),
    ]
    if (id) {
      invalidations.push(queryClient.invalidateQueries({
        predicate: expenseRecurrenceDetailInvalidationPredicate(
          id,
          tenantId,
          companyId,
        ),
      }))
    }
    await Promise.all(invalidations)
  }
}

export function useCreateExpenseRecurrence() {
  const { t } = useTranslation('expenses')
  const invalidate = useRecurrenceInvalidation()
  return useMutation({
    mutationFn: (data: CreateExpenseRecurrenceDTO) => recurrenceApi.create(data),
    onSuccess: async () => {
      await invalidate()
      toast.success(t('recurrences.messages.created'))
    },
    onError: () => toast.error(t('recurrences.messages.error')),
  })
}

export function useUpdateExpenseRecurrence() {
  const { t } = useTranslation('expenses')
  const invalidate = useRecurrenceInvalidation()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateExpenseRecurrenceDTO> }) =>
      recurrenceApi.update(id, data),
    onSuccess: async (template) => {
      await invalidate(template.id)
      toast.success(t('recurrences.messages.updated'))
    },
    onError: () => toast.error(t('recurrences.messages.error')),
  })
}

export function useDeleteExpenseRecurrence() {
  const { t } = useTranslation('expenses')
  const invalidate = useRecurrenceInvalidation()
  return useMutation({
    mutationFn: (id: string) => recurrenceApi.delete(id),
    onSuccess: async (_data, id) => {
      await invalidate(id)
      toast.success(t('recurrences.messages.deleted'))
    },
    onError: () => toast.error(t('recurrences.messages.error')),
  })
}

function useLifecycleMutation(action: 'pause' | 'resume') {
  const { t } = useTranslation('expenses')
  const invalidate = useRecurrenceInvalidation()
  return useMutation({
    mutationFn: (id: string) => recurrenceApi[action](id),
    onSuccess: async (template) => {
      await invalidate(template.id)
      toast.success(t(`recurrences.messages.${action === 'pause' ? 'paused' : 'resumed'}`))
    },
    onError: () => toast.error(t('recurrences.messages.error')),
  })
}

export function usePauseExpenseRecurrence() {
  return useLifecycleMutation('pause')
}

export function useResumeExpenseRecurrence() {
  return useLifecycleMutation('resume')
}
