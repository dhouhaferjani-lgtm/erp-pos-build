import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { importApi } from './importApi'
import { importsListInvalidationPredicate } from '../_invalidation'
import { importUploadErrorMessage } from '../jobErrorMessage'
import type { ImportJobListParams, ImportType } from '../types'

// Query Keys
export const importKeys = {
  all: ['imports'] as const,
  lists: () => [...importKeys.all, 'list'] as const,
  list: (filters: string) => [...importKeys.lists(), filters] as const,
  details: () => [...importKeys.all, 'detail'] as const,
  detail: (id: string) => [...importKeys.details(), id] as const,
  errors: (id: string) => [...importKeys.all, 'errors', id] as const,
  preview: (id: string) => [...importKeys.all, 'preview', id] as const,
  wizard: ['migration-wizard'] as const,
  wizardOrder: () => [...importKeys.wizard, 'order'] as const,
  wizardStatus: () => [...importKeys.wizard, 'status'] as const,
  dependencies: (type: ImportType) =>
    [...importKeys.wizard, 'dependencies', type] as const,
}

// Queries
export function useImportJobs(params: ImportJobListParams = {}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    // The filter and the page window are server-side, so they are part of the
    // cache identity — tenant/company stay the suffix (tenantScopedKey).
    queryKey: tenantScopedKey([
      ...importKeys.lists(),
      params.status ?? 'all',
      params.page ?? 1,
      params.per_page ?? 20,
    ]),
    queryFn: () => importApi.list(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function useImportJob(
  id: string,
  options?: {
    enabled?: boolean
    refetchInterval?: number | false
  }
) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const baseEnabled = options?.enabled ?? id.length > 0
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.detail(id)]),
    queryFn: () => importApi.getJob(id),
    enabled: baseEnabled && !!tenantId && !!companyId,
    ...(options?.refetchInterval !== undefined && { refetchInterval: options.refetchInterval }),
  })
}

export function useImportErrors(jobId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.errors(jobId)]),
    queryFn: () => importApi.getErrors(jobId),
    enabled: jobId.length > 0 && !!tenantId && !!companyId,
  })
}

export function useImportPreview(jobId: string, options?: { enabled?: boolean }) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const baseEnabled = options?.enabled ?? jobId.length > 0
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.preview(jobId)]),
    queryFn: () => importApi.getPreview(jobId),
    enabled: baseEnabled && jobId.length > 0 && !!tenantId && !!companyId,
  })
}

export function useWizardOrder() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.wizardOrder()]),
    queryFn: () => importApi.getWizardOrder(),
    enabled: !!tenantId && !!companyId,
  })
}

export function useMigrationStatus() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.wizardStatus()]),
    queryFn: () => importApi.getMigrationStatus(),
    enabled: !!tenantId && !!companyId,
  })
}

export function useDependencyCheck(type: ImportType) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...importKeys.dependencies(type)]),
    queryFn: () => importApi.checkDependencies(type),
    enabled: Boolean(type) && !!tenantId && !!companyId,
  })
}

// Mutations
export function useCreateImport() {
  const { t } = useTranslation('import')
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({
      type,
      file,
      columnMapping,
      reimportOf,
    }: {
      type: ImportType
      file: File
      columnMapping?: Record<string, string>
      reimportOf?: string
    }) => importApi.createJob(type, file, columnMapping, reimportOf),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: importsListInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('messages.uploadSuccess'))
    },
    onError: (error: Error) => {
      // The refusal is coded, and a header refusal names the columns left to
      // map — the very thing the wizard sends the operator back to fix. The
      // raw axios sentence is untranslated and says none of it.
      toast.error(importUploadErrorMessage(t, error))
    },
  })
}

export function useExecuteImport() {
  const { t } = useTranslation('import')
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (jobId: string) => importApi.executeImport(jobId),
    onSuccess: async (data) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [...importKeys.detail(data.id)],
        }),
        queryClient.invalidateQueries({
          predicate: importsListInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...importKeys.wizardStatus()],
        }),
      ])
      toast.success(t('messages.importStarted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.importError'))
    },
  })
}

export function useDeleteImport() {
  const { t } = useTranslation('import')
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (jobId: string) => importApi.deleteJob(jobId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: importsListInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('messages.deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.deleteError'))
    },
  })
}

export function useSuggestMapping() {
  return useMutation({
    mutationFn: ({
      type,
      headers,
    }: {
      type: ImportType
      headers: string[]
    }) => importApi.suggestMapping(type, headers),
  })
}
