import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18n from '@/lib/i18n'
import { getErrorMessage } from '@/lib/api'
import {
  listEarningRules,
  createEarningRule,
  updateEarningRule,
  deleteEarningRule,
  activateEarningRule,
  deactivateEarningRule,
} from '../api/earningRuleApi'
import type { CreateEarningRuleData, UpdateEarningRuleData } from '../types/loyalty'

const earningRulesKey = (programId: string) => ['loyalty-earning-rules', programId]

export function useEarningRules(programId: string) {
  return useQuery({
    queryKey: earningRulesKey(programId),
    queryFn: () => listEarningRules(programId),
    enabled: !!programId,
  })
}

export function useCreateEarningRule(programId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateEarningRuleData) => createEarningRule(programId, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: earningRulesKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: earningRulesKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: earningRulesKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: earningRulesKey(programId) })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: earningRulesKey(programId) })
      toast.success(i18n.t('loyalty:actions.deactivated'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
