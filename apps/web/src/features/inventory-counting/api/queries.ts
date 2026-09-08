import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { countingApi } from './countingApi'
import type { CountingFilters, CreateCountingFormData } from '../types'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { getErrorMessage, isApiError } from '@/lib/api'

// Query Keys
export const countingKeys = {
  all: ['counting'] as const,
  lists: () => [...countingKeys.all, 'list'] as const,
  list: (filters: CountingFilters) => [...countingKeys.lists(), filters] as const,
  details: () => [...countingKeys.all, 'detail'] as const,
  detail: (id: string) => [...countingKeys.details(), id] as const,
  reconciliation: (id: string) => [...countingKeys.all, 'reconciliation', id] as const,
  report: (id: string) => [...countingKeys.all, 'report', id] as const,
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

export function useCountingDetail(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.detail(id)]),
    queryFn: () => countingApi.getDetail(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useReconciliation(countingId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...countingKeys.reconciliation(countingId)]),
    queryFn: () => countingApi.getReconciliation(countingId),
    enabled: !!countingId && !!tenantId && !!companyId,
    refetchInterval: 30000,
  })
}

export function useDiscrepancyReport(countingId: string) {
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
        queryClient.invalidateQueries({ queryKey: [...countingKeys.dashboard()] }),
      ])
      toast.success(t('counting.messages.created'))
    },
    onError: (error: unknown) => {
      // Same reason as the activate/finalize handlers below: `error` is the RAW
      // AxiosError (the response interceptor re-rejects unchanged), so `.message`
      // is always "Request failed with status code 422". `getErrorMessage()`
      // reads the envelope, which is where the backend-localised refusal (e.g.
      // the product_location "location is required" 422) actually lives.
      toast.error(t('counting.messages.createFailed', { error: getErrorMessage(error) }))
    },
  })
}

export function useActivateCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: (id: string) => countingApi.activate(id),
    onSuccess: async (_, id) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(id)] }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.dashboard()] }),
      ])
      toast.success(t('counting.messages.activated'))
    },
    onError: (error: unknown) => {
      // Same reason as the finalize handler below: `error` is the RAW AxiosError
      // (the response interceptor re-rejects unchanged), so `.message` is always
      // "Request failed with status code 422". `getErrorMessage()` reads the
      // envelope, which is where the backend-localised refusal actually lives.
      toast.error(t('counting.messages.activateFailed', { error: getErrorMessage(error) }))
    },
  })
}

export function useCancelCounting() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      countingApi.cancel(id, reason),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(id)] }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.dashboard()] }),
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
    mutationFn: ({
      id,
      acknowledgeTerminalSyncRisk,
      terminalSyncHealthSignature,
    }: {
      id: string
      acknowledgeTerminalSyncRisk: boolean
      terminalSyncHealthSignature: string | null
    }) => countingApi.finalize(
      id,
      acknowledgeTerminalSyncRisk,
      terminalSyncHealthSignature,
    ),
    onSuccess: async (_, { id }) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(id)] }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.dashboard()] }),
      ])
      toast.success(t('counting.messages.finalized'))
    },
    onError: async (error: Error, { id }) => {
      if (
        isApiError(error)
        && error.response?.data.error.code === 'TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED'
      ) {
        await queryClient.invalidateQueries({
          queryKey: [...countingKeys.reconciliation(id)],
        })
        toast.error(t('counting.review.terminalSync.changed'))
        return
      }

      // LEDGER C-14(iv) / gate r1 IMPORTANT-1: `error` here is the RAW
      // AxiosError — `apiPost` unwraps only the SUCCESS body and the response
      // interceptor re-rejects unchanged (`lib/api.ts:361`), so `.message` is
      // always "Request failed with status code 422". `getErrorMessage()`
      // (`lib/api.ts:83-98`) reads the envelope, which is where the
      // backend-localised refusal actually lives.
      toast.error(t('counting.messages.finalizeFailed', { error: getErrorMessage(error) }))
    },
  })
}

export function useTriggerThirdCount() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ countingId, itemIds }: { countingId: string; itemIds: string[] }) =>
      countingApi.triggerThirdCount(countingId, itemIds),
    onSuccess: async (_, { countingId }) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [...countingKeys.reconciliation(countingId)],
        }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(countingId)] }),
      ])
      toast.success(t('counting.messages.thirdCountTriggered'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.thirdCountFailed', { error: error.message }))
    },
  })
}

export function useManualOverride(countingId: string) {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({
      itemId,
      quantity,
      notes,
    }: {
      itemId: string
      quantity: string
      notes: string
    }) => countingApi.manualOverride(itemId, quantity, notes),
    onSuccess: async () => {
      // Invalidate reconciliation for this counting
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [...countingKeys.reconciliation(countingId)],
        }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(countingId)] }),
      ])
      toast.success(t('counting.messages.overrideApplied'))
    },
    onError: (error: Error) => {
      // LEDGER C-14(iv) / gate r1 IMPORTANT-1 — see useFinalizeCounting above.
      // This is the handler that renders COUNTING_TRANSITION_REFUSED, the very
      // message C-14(iv) localised.
      toast.error(t('counting.messages.overrideFailed', { error: getErrorMessage(error) }))
    },
  })
}

export function useSetOpeningCost(countingId: string) {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ itemId, unitCost }: { itemId: string; unitCost: string }) =>
      countingApi.setOpeningCost(countingId, itemId, unitCost),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [...countingKeys.reconciliation(countingId)],
        }),
        queryClient.invalidateQueries({ queryKey: [...countingKeys.detail(countingId)] }),
      ])
      toast.success(t('counting.messages.openingCostUpdated'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.openingCostFailed', { error: error.message }))
    },
  })
}

export function useExportReport() {
  const { t } = useTranslation('inventory')

  return useMutation({
    mutationFn: ({ id, format }: { id: string; format: 'pdf' | 'xlsx' }) =>
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
    mutationFn: (id: string) => countingApi.sendReminder(id),
    onSuccess: () => {
      toast.success(t('counting.messages.reminderSent'))
    },
    onError: (error: Error) => {
      toast.error(t('counting.messages.reminderFailed', { error: error.message }))
    },
  })
}
