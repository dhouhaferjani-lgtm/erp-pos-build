import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { replenishmentApi } from './replenishmentApi'
import type { CaptureReplenishmentInput, CreatePoActionInput, CreateTransferActionInput, RejectActionInput, ReplenishmentListFilters } from '../types'

const namespace = 'replenishment'
function enabled() { return Boolean(useAuthStore.getState().user && useCompanyStore.getState().currentCompanyId) }
export function useOpenReplenishment(filters: Omit<ReplenishmentListFilters, 'status'> = {}) { return useQuery({ queryKey: tenantScopedKey([namespace, 'open', filters]), queryFn: () => replenishmentApi.open(filters), enabled: enabled() }) }
export function useReplenishmentHistory(filters: ReplenishmentListFilters) { return useQuery({ queryKey: tenantScopedKey([namespace, 'history', filters]), queryFn: () => replenishmentApi.history(filters), enabled: enabled() }) }
function useInvalidatingMutation<T>(mutationFn: (input: T) => Promise<unknown>, extras: string[] = []) {
  const client = useQueryClient()
  return useMutation({ mutationFn, onSuccess: () => { void client.invalidateQueries({ queryKey: tenantScopedKey([namespace]) }); for (const key of extras) void client.invalidateQueries({ queryKey: tenantScopedKey([key]) }) } })
}
export function useCaptureReplenishment() { return useInvalidatingMutation<CaptureReplenishmentInput>(replenishmentApi.capture) }
export function useCreateTransferAction() { return useInvalidatingMutation<CreateTransferActionInput>(replenishmentApi.createTransfer, ['stock-transfers', 'stock-levels']) }
export function useCreatePoAction() { return useInvalidatingMutation<CreatePoActionInput>(replenishmentApi.createPo) }
export function useRejectAction() { return useInvalidatingMutation<RejectActionInput>(replenishmentApi.reject) }
