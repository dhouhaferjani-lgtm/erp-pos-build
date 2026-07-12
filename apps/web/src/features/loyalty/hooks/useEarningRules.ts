import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  listEarningRules,
  createEarningRule,
  updateEarningRule,
  deleteEarningRule,
  activateEarningRule,
  deactivateEarningRule,
} from '../api/earningRuleApi'
import type { CreateEarningRuleData, UpdateEarningRuleData } from '../types/loyalty'

export const earningRulesKey = (programId: string) => ['loyalty-earning-rules', programId] as const

export function useEarningRules(programId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey([...earningRulesKey(programId)]),
    queryFn: () => listEarningRules(programId),
    enabled: !!programId && !!tenantId && !!companyId,
  })
}

export function useCreateEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateEarningRuleData) => createEarningRule(programId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...earningRulesKey(programId)] })
      toast.success(i18n.t('loyalty:actions.created'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateEarningRuleData }) => updateEarningRule(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...earningRulesKey(programId)] })
      toast.success(i18n.t('loyalty:actions.updated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteEarningRule(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...earningRulesKey(programId)] })
      toast.success(i18n.t('loyalty:actions.deleted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useActivateEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activateEarningRule(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...earningRulesKey(programId)] })
      toast.success(i18n.t('loyalty:actions.activated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeactivateEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deactivateEarningRule(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [...earningRulesKey(programId)] })
      toast.success(i18n.t('loyalty:actions.deactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
