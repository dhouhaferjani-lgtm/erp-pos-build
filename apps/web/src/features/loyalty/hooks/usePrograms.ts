import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listPrograms,
  getProgram,
  createProgram,
  updateProgram,
  deleteProgram,
  activateProgram,
  deactivateProgram,
  listActivePrograms,
} from '../api/programApi'
import type { CreateProgramData, UpdateProgramData } from '../types/loyalty'

export const PROGRAMS_KEY = ['loyalty-programs'] as const

export function programsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'loyalty-programs' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function usePrograms() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...PROGRAMS_KEY]),
    queryFn: listPrograms,
    enabled: !!tenantId && !!companyId,
  })
}

export function useProgram(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...PROGRAMS_KEY, id]),
    queryFn: () => getProgram(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useActivePrograms() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...PROGRAMS_KEY, 'active']),
    queryFn: listActivePrograms,
    enabled: !!tenantId && !!companyId,
  })
}

export function useCreateProgram() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateProgramData) => createProgram(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: programsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateProgram() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateProgramData }) => updateProgram(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: programsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteProgram() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => deleteProgram(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: programsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivateProgram() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => activateProgram(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: programsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeactivateProgram() {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => deactivateProgram(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: programsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18n.t('loyalty:actions.deactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
