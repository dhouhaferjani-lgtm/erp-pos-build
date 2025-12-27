import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { importApi } from './importApi'
import type { ImportType } from '../types'

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
export function useImportJobs() {
  return useQuery({
    queryKey: importKeys.lists(),
    queryFn: () => importApi.list(),
  })
}

export function useImportJob(
  id: string,
  options?: {
    enabled?: boolean
    refetchInterval?: number | false
  }
) {
  return useQuery({
    queryKey: importKeys.detail(id),
    queryFn: () => importApi.getJob(id),
    enabled: options?.enabled ?? id.length > 0,
    ...(options?.refetchInterval !== undefined && { refetchInterval: options.refetchInterval }),
  })
}

export function useImportErrors(jobId: string) {
  return useQuery({
    queryKey: importKeys.errors(jobId),
    queryFn: () => importApi.getErrors(jobId),
    enabled: jobId.length > 0,
  })
}

export function useImportPreview(jobId: string) {
  return useQuery({
    queryKey: importKeys.preview(jobId),
    queryFn: () => importApi.getPreview(jobId),
    enabled: jobId.length > 0,
  })
}

export function useWizardOrder() {
  return useQuery({
    queryKey: importKeys.wizardOrder(),
    queryFn: () => importApi.getWizardOrder(),
  })
}

export function useMigrationStatus() {
  return useQuery({
    queryKey: importKeys.wizardStatus(),
    queryFn: () => importApi.getMigrationStatus(),
  })
}

export function useDependencyCheck(type: ImportType) {
  return useQuery({
    queryKey: importKeys.dependencies(type),
    queryFn: () => importApi.checkDependencies(type),
    enabled: Boolean(type),
  })
}

// Mutations
export function useCreateImport() {
  const { t } = useTranslation('import')
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      type,
      file,
      columnMapping,
    }: {
      type: ImportType
      file: File
      columnMapping?: Record<string, string>
    }) => importApi.createJob(type, file, columnMapping),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: importKeys.lists() })
      toast.success(t('messages.uploadSuccess'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('messages.uploadError'))
    },
  })
}

export function useExecuteImport() {
  const { t } = useTranslation('import')
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (jobId: string) => importApi.executeImport(jobId),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: importKeys.detail(data.id) })
      void queryClient.invalidateQueries({ queryKey: importKeys.lists() })
      void queryClient.invalidateQueries({ queryKey: importKeys.wizardStatus() })
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

  return useMutation({
    mutationFn: (jobId: string) => importApi.deleteJob(jobId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: importKeys.lists() })
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
