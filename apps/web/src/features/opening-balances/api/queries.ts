import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { openingBalancesApi } from './openingBalancesApi'
import { useCompanyStore } from '../../../stores/companyStore'
import type {
  CreateBatchPayload,
  ImportRowPayload,
} from '../types'

// Query Keys
export const openingBalanceKeys = {
  all: ['opening-balances'] as const,
  types: () => [...openingBalanceKeys.all, 'types'] as const,
  status: (companyId: string) => [...openingBalanceKeys.all, 'status', companyId] as const,
  lists: () => [...openingBalanceKeys.all, 'list'] as const,
  list: (companyId: string) => [...openingBalanceKeys.lists(), companyId] as const,
  details: () => [...openingBalanceKeys.all, 'detail'] as const,
  detail: (companyId: string, batchId: string) =>
    [...openingBalanceKeys.details(), companyId, batchId] as const,
  rows: (companyId: string, batchId: string) =>
    [...openingBalanceKeys.all, 'rows', companyId, batchId] as const,
  preview: (companyId: string, batchId: string) =>
    [...openingBalanceKeys.all, 'preview', companyId, batchId] as const,
}

export function openingBalanceRowsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
  batchId: string,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 6 &&
      k[0] === 'opening-balances' &&
      k[1] === 'rows' &&
      k[2] === companyId &&
      k[3] === batchId &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

// Queries

/**
 * Get available batch types
 */
export function useOpeningBatchTypes() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.types()]),
    queryFn: () => openingBalancesApi.getTypes(),
    enabled: !!tenantId && !!companyId,
    staleTime: 1000 * 60 * 60, // Types are static, cache for 1 hour
  })
}

/**
 * Get status for all batch types
 */
export function useOpeningBatchStatus() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.status(companyId ?? '')]),
    queryFn: () => openingBalancesApi.getStatus(companyId!),
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * List all opening balance batches
 */
export function useOpeningBatches() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.list(companyId ?? '')]),
    queryFn: () => openingBalancesApi.list(companyId!),
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * Get a single batch
 */
export function useOpeningBatch(batchId: string | undefined) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.detail(companyId ?? '', batchId ?? '')]),
    queryFn: () => openingBalancesApi.get(companyId!, batchId!),
    enabled: !!tenantId && !!companyId && Boolean(batchId),
  })
}

/**
 * Get import rows for a batch
 */
export function useOpeningBatchRows(
  batchId: string | undefined,
  params?: { page?: number; per_page?: number; status?: string }
) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.rows(companyId ?? '', batchId ?? ''), params]),
    queryFn: () => openingBalancesApi.getRows(companyId!, batchId!, params),
    enabled: !!tenantId && !!companyId && Boolean(batchId),
  })
}

/**
 * Get post preview for a batch
 */
export function useOpeningBatchPreview(batchId: string | undefined) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...openingBalanceKeys.preview(companyId ?? '', batchId ?? '')]),
    queryFn: () => openingBalancesApi.preview(companyId!, batchId!),
    enabled: !!tenantId && !!companyId && Boolean(batchId),
  })
}

// Mutations

/**
 * Create a new opening balance batch
 */
export function useCreateOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (payload: CreateBatchPayload) =>
      openingBalancesApi.create(companyId!, payload),
    onSuccess: async () => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.list(companyId)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.status(companyId)]) }),
      ])
      toast.success(t('openingBalances.messages.batchCreated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.createError'))
    },
  })
}

/**
 * Delete an opening balance batch
 */
export function useDeleteOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.delete(companyId!, batchId),
    onSuccess: async () => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.list(companyId)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.status(companyId)]) }),
      ])
      toast.success(t('openingBalances.messages.batchDeleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.deleteError'))
    },
  })
}

/**
 * Import rows into a batch
 */
export function useImportOpeningRows() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ batchId, payload }: { batchId: string; payload: ImportRowPayload }) =>
      openingBalancesApi.importRows(companyId!, batchId, payload),
    onSuccess: async (data, variables) => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...openingBalanceKeys.detail(companyId, variables.batchId)]),
        }),
        queryClient.invalidateQueries({
          predicate: openingBalanceRowsInvalidationPredicate(tenantId, companyId, variables.batchId),
        }),
      ])
      toast.success(t('openingBalances.messages.rowsImported', { count: data.imported }))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.importError'))
    },
  })
}

/**
 * Validate a batch
 */
export function useValidateOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.validate(companyId!, batchId),
    onSuccess: async (data, batchId) => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...openingBalanceKeys.detail(companyId, batchId)]),
        }),
        queryClient.invalidateQueries({
          predicate: openingBalanceRowsInvalidationPredicate(tenantId, companyId, batchId),
        }),
      ])
      if (data.valid) {
        toast.success(t('openingBalances.messages.validationSuccess'))
      } else {
        toast.warning(t('openingBalances.messages.validationHasErrors', { count: data.invalid_rows }))
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.validationError'))
    },
  })
}

/**
 * Post a batch
 */
export function usePostOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.post(companyId!, batchId),
    onSuccess: async (_data, batchId) => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...openingBalanceKeys.detail(companyId, batchId)]),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.status(companyId)]) }),
      ])
      toast.success(t('openingBalances.messages.postSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.postError'))
    },
  })
}

/**
 * Lock a batch
 */
export function useLockOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.lock(companyId!, batchId),
    onSuccess: async (_data, batchId) => {
      if (!tenantId || !companyId) return
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: tenantScopedKey([...openingBalanceKeys.detail(companyId, batchId)]),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.status(companyId)]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([...openingBalanceKeys.list(companyId)]) }),
      ])
      toast.success(t('openingBalances.messages.lockSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.lockError'))
    },
  })
}
