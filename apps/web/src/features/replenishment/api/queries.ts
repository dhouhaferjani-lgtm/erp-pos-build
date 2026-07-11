import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { replenishmentApi } from './replenishmentApi'
import type { ReplenishmentListFilters } from '../types'

const namespace = 'replenishment'
function enabled() { return Boolean(useAuthStore.getState().user && useCompanyStore.getState().currentCompanyId) }
export function useOpenReplenishment(filters: Omit<ReplenishmentListFilters, 'status'> = {}) { return useQuery({ queryKey: tenantScopedKey([namespace, 'open', filters]), queryFn: () => replenishmentApi.open(filters), enabled: enabled() }) }
export function useReplenishmentHistory(filters: ReplenishmentListFilters) { return useQuery({ queryKey: tenantScopedKey([namespace, 'history', filters]), queryFn: () => replenishmentApi.history(filters), enabled: enabled() }) }
function useInvalidatingMutation<TInput, TResult>(mutationFn: (input: TInput) => Promise<TResult>, extras: string[] = []) {
  const client = useQueryClient()
  return useMutation({ mutationFn, onSuccess: () => { void client.invalidateQueries({ queryKey: [namespace] }); for (const key of extras) void client.invalidateQueries({ queryKey: [key] }) } })
}
export function useCaptureReplenishment() { return useInvalidatingMutation(replenishmentApi.capture) }
export function useCreateTransferAction() { return useInvalidatingMutation(replenishmentApi.createTransfer, ['stock-transfers', 'stock-levels']) }
export function useCreatePoAction() { return useInvalidatingMutation(replenishmentApi.createPo) }
export function useRejectAction() { return useInvalidatingMutation(replenishmentApi.reject) }
