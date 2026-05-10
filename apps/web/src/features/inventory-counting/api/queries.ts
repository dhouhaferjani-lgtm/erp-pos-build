import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { countingApi } from './countingApi'
import type { CountingFilters, CreateCountingFormData } from '../types'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'

// Query Keys
export const countingKeys = {
  all: ['counting'] as const,
  lists: () => [...countingKeys.all, 'list'] as const,
  list: (filters: CountingFilters) => [...countingKeys.lists(), filters] as const,
  details: () => [...countingKeys.all, 'detail'] as const,
  detail: (id: number) => [...countingKeys.details(), id] as const,
  reconciliation: (id: number) => [...countingKeys.all, 'reconciliation', id] as const,
  report: (id: number) => [...countingKeys.all, 'report', id] as const,
  dashboard: () => [...countingKeys.all, 'dashboard'] as const,
}

export function countingListInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'counting' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

// Queries
export function useCountingDashboard() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.dashboard()]),
    queryFn: countingApi.getDashboard,
    enabled: !!tenantId && !!companyId,
    refetchInterval: 30000, // Refresh every 30s
  })
}

export function useCountingList(filters: CountingFilters) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.list(filters)]),
    queryFn: () => countingApi.list(filters),
    enabled: !!tenantId && !!companyId,
  })
}

export function useCountingDetail(id: number) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.detail(id)]),
    queryFn: () => countingApi.getDetail(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useReconciliation(countingId: number) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.reconciliation(countingId)]),
    queryFn: () => countingApi.getReconciliation(countingId),
    enabled: !!countingId && !!tenantId && !!companyId,
  })
}

export function useDiscrepancyReport(countingId: number) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.report(countingId)]),
    queryFn: () => countingApi.getReport(countingId),
    enabled: !!countingId && !!tenantId && !!companyId,
  })
}

// Mutations
export function useCreateCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateCountingFormData) => countingApi.create(data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: countingListInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.dashboard()]) }),
      ])
      toast.success(t('counting.messages.created'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.createFailed', { error: error.message }))
    },
  })
}

export function useActivateCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: (id: number) => countingApi.activate(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.detail(id)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.dashboard()]) }),
      ])
      toast.success(t('counting.messages.activated'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.activateFailed', { error: error.message }))
    },
  })
}

export function useCancelCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      countingApi.cancel(id, reason),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.detail(id)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.dashboard()]) }),
      ])
      toast.success(t('counting.messages.cancelled'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.cancelFailed', { error: error.message }))
    },
  })
}

export function useFinalizeCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: (id: number) => countingApi.finalize(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.detail(id)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.dashboard()]) }),
      ])
      toast.success(t('counting.messages.finalized'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.finalizeFailed', { error: error.message }))
    },
  })
}

export function useTriggerThirdCount() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ countingId, itemIds }: { countingId: number; itemIds: number[] }) =>
      countingApi.triggerThirdCount(countingId, itemIds),
    onSuccess: async (_, { countingId }) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...countingKeys.reconciliation(countingId)]),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.detail(countingId)]) }),
      ])
      toast.success(t('counting.messages.thirdCountTriggered'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.thirdCountFailed', { error: error.message }))
    },
  })
}

export function useManualOverride(countingId: number) {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({
      itemId,
      quantity,
      notes,
    }: {
      itemId: number
      quantity: number
      notes: string
    }) => countingApi.manualOverride(itemId, quantity, notes),
    onSuccess: async () => {
      // Invalidate reconciliation for this counting
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...countingKeys.reconciliation(countingId)]),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...countingKeys.detail(countingId)]) }),
      ])
      toast.success(t('counting.messages.overrideApplied'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.overrideFailed', { error: error.message }))
    },
  })
}

export function useExportReport() {
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ id, format }: { id: number; format: 'pdf' | 'xlsx' }) =>
      countingApi.exportReport(id, format),
    onSuccess: (blob, { format }) => {
      // Download the file
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `discrepancy-report.${format}`
      document.body.appendChild(a)
      a.click()
      window.URL.revokeObjectURL(url)
      a.remove()
      toast.success(t('counting.messages.reportDownloaded'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.exportFailed', { error: error.message }))
    },
  })
}

export function useSendReminder() {
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: (id: number) => countingApi.sendReminder(id),
    onSuccess: () => {
      toast.success(t('counting.messages.reminderSent'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.reminderFailed', { error: error.message }))
    },
  })
}
