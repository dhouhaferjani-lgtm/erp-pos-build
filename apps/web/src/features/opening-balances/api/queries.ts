import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
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

// Queries

/**
 * Get available batch types
 */
export function useOpeningBatchTypes() {
  return useQuery({
    queryKey: openingBalanceKeys.types(),
    queryFn: () => openingBalancesApi.getTypes(),
    staleTime: 1000 * 60 * 60, // Types are static, cache for 1 hour
  })
}

/**
 * Get status for all batch types
 */
export function useOpeningBatchStatus() {
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useQuery({
    queryKey: openingBalanceKeys.status(companyId ?? ''),
    queryFn: () => openingBalancesApi.getStatus(companyId!),
    enabled: Boolean(companyId),
  })
}

/**
 * List all opening balance batches
 */
export function useOpeningBatches() {
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useQuery({
    queryKey: openingBalanceKeys.list(companyId ?? ''),
    queryFn: () => openingBalancesApi.list(companyId!),
    enabled: Boolean(companyId),
  })
}

/**
 * Get a single batch
 */
export function useOpeningBatch(batchId: string | undefined) {
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useQuery({
    queryKey: openingBalanceKeys.detail(companyId ?? '', batchId ?? ''),
    queryFn: () => openingBalancesApi.get(companyId!, batchId!),
    enabled: Boolean(companyId) && Boolean(batchId),
  })
}

/**
 * Get import rows for a batch
 */
export function useOpeningBatchRows(
  batchId: string | undefined,
  params?: { page?: number; per_page?: number; status?: string }
) {
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useQuery({
    queryKey: [...openingBalanceKeys.rows(companyId ?? '', batchId ?? ''), params],
    queryFn: () => openingBalancesApi.getRows(companyId!, batchId!, params),
    enabled: Boolean(companyId) && Boolean(batchId),
  })
}

/**
 * Get post preview for a batch
 */
export function useOpeningBatchPreview(batchId: string | undefined) {
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useQuery({
    queryKey: openingBalanceKeys.preview(companyId ?? '', batchId ?? ''),
    queryFn: () => openingBalancesApi.preview(companyId!, batchId!),
    enabled: Boolean(companyId) && Boolean(batchId),
  })
}

// Mutations

/**
 * Create a new opening balance batch
 */
export function useCreateOpeningBatch() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: (payload: CreateBatchPayload) =>
      openingBalancesApi.create(companyId!, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.list(companyId!) })
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.status(companyId!) })
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
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.delete(companyId!, batchId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.list(companyId!) })
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.status(companyId!) })
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
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: ({ batchId, payload }: { batchId: string; payload: ImportRowPayload }) =>
      openingBalancesApi.importRows(companyId!, batchId, payload),
    onSuccess: (data, variables) => {
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.detail(companyId!, variables.batchId),
      })
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.rows(companyId!, variables.batchId),
      })
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
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.validate(companyId!, batchId),
    onSuccess: (data, batchId) => {
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.detail(companyId!, batchId),
      })
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.rows(companyId!, batchId),
      })
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
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.post(companyId!, batchId),
    onSuccess: (_data, batchId) => {
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.detail(companyId!, batchId),
      })
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.status(companyId!) })
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
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return useMutation({
    mutationFn: (batchId: string) =>
      openingBalancesApi.lock(companyId!, batchId),
    onSuccess: (_data, batchId) => {
      void queryClient.invalidateQueries({
        queryKey: openingBalanceKeys.detail(companyId!, batchId),
      })
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.status(companyId!) })
      void queryClient.invalidateQueries({ queryKey: openingBalanceKeys.list(companyId!) })
      toast.success(t('openingBalances.messages.lockSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('openingBalances.messages.lockError'))
    },
  })
}
